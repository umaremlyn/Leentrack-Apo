import 'package:uuid/uuid.dart';

import '../auth/auth_service.dart';
import '../auth/authenticated_client.dart';
import '../data/local_db.dart';
import '../features/pos/pos_service.dart';
import '../sync/sync_api.dart';
import '../sync/sync_engine.dart';

/// Everything that exists only while someone is working inside one business.
class WorkSession {
  WorkSession({required this.user, required this.membership, required this.db, required this.engine, required this.pos, required this.client});
  final SignedIn user;
  final Membership membership;
  final LocalDb db;
  final SyncEngine engine;
  final PosService pos;
  final AuthenticatedClient client;

  String get role => membership.role;
  bool get isManager => role == 'owner' || role == 'manager';

  void close() => db.close();

  static WorkSession open({
    required SignedIn user,
    required Membership membership,
    required LocalDb db,
    required AuthenticatedClient client,
    required String deviceId,
    DateTime Function()? clock,
  }) {
    final now = clock ?? DateTime.now;
    return WorkSession(
      user: user,
      membership: membership,
      db: db,
      client: client,
      engine: SyncEngine(db: db, api: HttpSyncApi(client), deviceId: deviceId, clock: now),
      pos: PosService(db: db, newId: () => const Uuid().v4(), clock: now),
    );
  }
}
