import 'dart:async';
import 'dart:io';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';
import 'package:uuid/uuid.dart';

import '../auth/auth_service.dart';
import '../auth/authenticated_client.dart';
import '../auth/token_store.dart';
import '../config/app_config.dart';
import '../data/local_db.dart';
import '../network/api_client.dart';
import '../network/api_exception.dart';
import '../sync/sync_engine.dart';
import 'session.dart';

final tokenStoreProvider = Provider<TokenStore>((ref) => SecureTokenStore());

final authClientProvider = Provider<AuthenticatedClient>((ref) {
  final c = AuthenticatedClient(
    api: ApiClient(baseUrl: AppConfig.apiBaseUrl),
    store: ref.watch(tokenStoreProvider),
    onSessionExpired: () => Future.microtask(() => ref.read(sessionProvider.notifier).sessionExpired()),
  );
  return c;
});

/// One database file per user per business, so two people sharing a till never see each other's queue.
/// Overridden in tests with an in-memory database.
final dbFactoryProvider = Provider<Future<LocalDb> Function(String userId, String businessId)>((ref) => (userId, businessId) async {
      final dir = await getApplicationSupportDirectory();
      await Directory(dir.path).create(recursive: true);
      return LocalDb.open(p.join(dir.path, 'leentrack_${userId}_$businessId.db'));
    });

enum Stage { loading, signedOut, chooseBusiness, ready }

class AppState {
  const AppState({required this.stage, this.user, this.work, this.message, this.busy = false});
  final Stage stage;
  final SignedIn? user;
  final WorkSession? work;
  final String? message;
  final bool busy;
}

class SessionController extends Notifier<AppState> {
  @override
  AppState build() {
    ref.onDispose(() {
      _timer?.cancel();
      _work?.close(); // state cannot be read while disposing
    });
    Future.microtask(_boot);
    return const AppState(stage: Stage.loading);
  }

  AuthService get _auth => AuthService(client: ref.read(authClientProvider), store: ref.read(tokenStoreProvider), deviceId: _deviceId ?? 'unknown');
  String? _deviceId;
  Timer? _timer;
  WorkSession? _work;

  Future<void> _boot() async {
    _deviceId = await ref.read(tokenStoreProvider).deviceId(() => const Uuid().v4());
    try {
      final s = await _auth.resume();
      if (s == null) {
        state = const AppState(stage: Stage.signedOut);
        return;
      }
      await _afterSignIn(s, remembered: await ref.read(tokenStoreProvider).readBusinessId());
    } on ApiException catch (e) {
      // offline at start-up: the refresh token is kept; sign-in is retried when the network is back
      state = AppState(stage: Stage.signedOut, message: e.isNetwork ? 'No connection. Connect once to sign in; your queued sales are kept.' : e.message);
    }
  }

  Future<void> login(String email, String password) async {
    state = AppState(stage: Stage.signedOut, busy: true, user: state.user);
    try {
      final s = await _auth.login(email, password);
      await _afterSignIn(s);
    } on ApiException catch (e) {
      state = AppState(stage: Stage.signedOut, message: e.isNetwork ? 'Cannot reach the server. Check your connection.' : e.message);
    }
  }

  Future<void> _afterSignIn(SignedIn s, {String? remembered}) async {
    final ms = s.memberships;
    if (ms.isEmpty) {
      state = AppState(stage: Stage.signedOut, message: 'This account does not belong to any business.');
      return;
    }
    final pick = ms.length == 1 ? ms.first : ms.where((m) => m.businessId == remembered).firstOrNull;
    if (pick == null) {
      state = AppState(stage: Stage.chooseBusiness, user: s);
      return;
    }
    await _open(s, pick);
  }

  Future<void> chooseBusiness(Membership m) async {
    final u = state.user;
    if (u == null) return;
    await _open(u, m);
  }

  Future<void> _open(SignedIn s, Membership m) async {
    _work?.close();
    await _auth.selectBusiness(m.businessId);
    final db = await ref.read(dbFactoryProvider)(s.userId, m.businessId);
    final w = WorkSession.open(user: s, membership: m, db: db, client: ref.read(authClientProvider), deviceId: _deviceId ?? 'unknown');
    _work = w;
    state = AppState(stage: Stage.ready, user: s, work: w);
    ref.read(syncProvider.notifier).attach(w);
    _timer?.cancel();
    _timer = Timer.periodic(const Duration(seconds: 60), (_) => ref.read(syncProvider.notifier).trigger());
    unawaited(ref.read(syncProvider.notifier).trigger());
  }

  Future<void> switchBusiness() async {
    final u = state.user;
    _timer?.cancel();
    _work?.close();
    _work = null;
    state = AppState(stage: Stage.chooseBusiness, user: u);
  }

  Future<void> logout() async {
    _timer?.cancel();
    // Never throw away unsent sales silently: the queue lives in the per-user database and is kept for the next sign-in.
    _work?.close();
    _work = null;
    await _auth.logout();
    state = const AppState(stage: Stage.signedOut);
  }

  void sessionExpired() {
    _timer?.cancel();
    _work?.close();
    _work = null;
    state = const AppState(stage: Stage.signedOut, message: 'Your session ended. Please sign in again. Queued sales are kept on this device.');
  }
}

final sessionProvider = NotifierProvider<SessionController, AppState>(SessionController.new);

class SyncStatus {
  const SyncStatus({this.state = SyncState.idle, this.pending = 0, this.attention = 0, this.lastReport, this.lastSync});
  final SyncState state;
  final int pending;
  final int attention;
  final SyncReport? lastReport;
  final DateTime? lastSync;
}

class SyncController extends Notifier<SyncStatus> {
  WorkSession? _w;
  @override
  SyncStatus build() => const SyncStatus();

  void attach(WorkSession w) {
    _w = w;
    refresh();
  }

  void refresh() {
    final w = _w;
    if (w == null) return;
    state = SyncStatus(
      state: state.state,
      pending: w.db.count('pending'),
      attention: w.db.count('conflict') + w.db.count('rejected'),
      lastReport: state.lastReport,
      lastSync: state.lastSync,
    );
  }

  Future<void> trigger() async {
    final w = _w;
    if (w == null) return;
    state = SyncStatus(state: SyncState.syncing, pending: state.pending, attention: state.attention, lastReport: state.lastReport, lastSync: state.lastSync);
    SyncReport r;
    try {
      r = await w.engine.run();
    } catch (e) {
      // e.g. the database was closed because the user signed out while a sync was running; nothing is lost, it stays queued
      if (identical(_w, w)) state = SyncStatus(state: SyncState.failed, pending: state.pending, attention: state.attention, lastReport: SyncReport(failure: '$e'), lastSync: state.lastSync);
      return;
    }
    if (!identical(_w, w)) return; // the user switched business while this ran
    final stamp = w.db.meta('last_sync');
    state = SyncStatus(
      state: w.engine.state,
      pending: w.db.count('pending'),
      attention: w.db.count('conflict') + w.db.count('rejected'),
      lastReport: r,
      lastSync: stamp == null ? null : DateTime.tryParse(stamp),
    );
  }
}

final syncProvider = NotifierProvider<SyncController, SyncStatus>(SyncController.new);
