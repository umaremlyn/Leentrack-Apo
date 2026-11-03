import 'dart:convert';

import '../data/local_db.dart';
import '../network/api_exception.dart';
import 'sync_api.dart';

enum SyncState { idle, syncing, offline, attention, failed }

class SyncReport {
  const SyncReport({this.pushed = 0, this.applied = 0, this.conflicts = 0, this.rejected = 0, this.errors = 0, this.pulledRows = 0, this.offline = false, this.failure});
  final int pushed, applied, conflicts, rejected, errors, pulledRows;
  final bool offline;
  final String? failure;
}

/// Pushes queued operations and pulls changes, following docs/14_SYNC_PROTOCOL.md.
/// One run at a time (a second call while running returns the running result). Never throws: failures are reported and the data stays queued.
class SyncEngine {
  SyncEngine({required this.db, required this.api, required this.deviceId, DateTime Function()? clock, this.fullResyncEvery = const Duration(hours: 24)})
      : _now = clock ?? DateTime.now;

  final LocalDb db;
  final SyncApi api;
  final String deviceId;
  final DateTime Function() _now;
  final Duration fullResyncEvery;

  static const batchSize = 100;

  Future<SyncReport>? _running;
  SyncState state = SyncState.idle;

  Future<SyncReport> run() => _running ??= _run().whenComplete(() => _running = null);

  static Duration backoff(int attempts) {
    final s = [5, 15, 60, 300, 900, 3600][attempts.clamp(0, 5)];
    return Duration(seconds: s);
  }

  Future<SyncReport> _run() async {
    state = SyncState.syncing;
    var pushed = 0, applied = 0, conflicts = 0, rejected = 0, errors = 0, rows = 0;
    try {
      // ---- push, in the order the cashier did things, until nothing is due
      final sentThisRun = <String>{}; // each operation is offered at most once per run, whatever the back-off says
      while (true) {
        final due = db.dueOperations(_now(), limit: batchSize).where((o) => !sentThisRun.contains(o['id'])).toList();
        if (due.isEmpty) break;
        sentThisRun.addAll(due.map((o) => o['id'] as String));
        final res = await api.push(
          deviceId: deviceId,
          deviceTime: _now(),
          operations: [
            for (final o in due)
              {'id': o['id'], 'type': o['type'], 'client_created_at': o['client_created_at'], 'payload': jsonDecode(o['payload'] as String)},
          ],
        );
        pushed += due.length;
        final byId = {for (final o in due) o['id'] as String: o};
        final seen = <String>{};
        for (final r in (res['results'] as List).cast<Map<String, dynamic>>()) {
          final id = r['id'] as String;
          final op = byId[id];
          if (op == null) continue;
          seen.add(id);
          final attempts = op['attempts'] as int;
          switch (r['status']) {
            case 'applied':
              applied++;
              db.setOperationOutcome(id, status: 'applied', result: (r['result'] as Map?)?.cast<String, dynamic>(), countAttempt: true);
            case 'discarded':
              db.setOperationOutcome(id, status: 'discarded', message: r['message'] as String?);
            case 'rejected':
              rejected++;
              db.setOperationOutcome(id, status: 'rejected', code: r['code'] as String?, message: r['message'] as String?, countAttempt: true);
            case 'conflict':
              conflicts++;
              db.setOperationOutcome(id, status: 'conflict', code: r['code'] as String?, message: r['message'] as String?, nextAttempt: _now().add(backoff(attempts)), countAttempt: true);
            default:
              errors++;
              db.setOperationOutcome(id, status: 'pending', code: r['code'] as String?, message: r['message'] as String?, nextAttempt: _now().add(backoff(attempts)), countAttempt: true);
          }
        }
        // anything the server did not answer for stays queued and is tried later, never assumed done
        for (final o in due) {
          if (!seen.contains(o['id'])) db.setOperationOutcome(o['id'] as String, status: 'pending', nextAttempt: _now().add(backoff(o['attempts'] as int)), countAttempt: true);
        }
        // a batch that produced no progress must not spin: operations are now backed off, so the next dueOperations() call excludes them
      }

      // ---- pull until the server says it is done
      final full = _needsFullResync();
      var cursors = full ? <String, String>{} : _cursors();
      var guard = 0;
      while (guard++ < 1000) {
        final res = await api.pull(cursors: cursors, limit: 500);
        rows += db.tx(() => _apply(res));
        cursors = (res['cursors'] as Map).map((k, v) => MapEntry('$k', '$v'));
        if (res['has_more'] != true) {
          db.setMetaJson('cursors', cursors);
          if (full) db.setMeta('last_full_sync', _now().toUtc().toIso8601String());
          db.setMeta('last_sync', _now().toUtc().toIso8601String());
          break;
        }
        db.setMetaJson('cursors_partial', cursors); // progress of an interrupted download is not trusted as "done"
      }
      db.purgeApplied();

      final attention = db.count('conflict') + db.count('rejected');
      state = attention > 0 ? SyncState.attention : SyncState.idle;
      return SyncReport(pushed: pushed, applied: applied, conflicts: conflicts, rejected: rejected, errors: errors, pulledRows: rows);
    } on ApiException catch (e) {
      state = e.isNetwork ? SyncState.offline : SyncState.failed;
      return SyncReport(pushed: pushed, applied: applied, conflicts: conflicts, rejected: rejected, errors: errors, pulledRows: rows, offline: e.isNetwork, failure: e.isNetwork ? null : '${e.code}: ${e.message}');
    }
  }

  bool _needsFullResync() {
    final last = db.meta('last_full_sync');
    if (last == null) return true;
    return _now().difference(DateTime.parse(last)) > fullResyncEvery;
  }

  Map<String, String> _cursors() => db.metaJson('cursors').map((k, v) => MapEntry(k, '$v'));

  int _apply(Map<String, dynamic> res) {
    final ch = (res['changes'] as Map?)?.cast<String, dynamic>() ?? {};
    var n = 0;
    for (final p in (ch['products'] as List? ?? const []).cast<Map<String, dynamic>>()) {
      n++;
      if (p['archived_at'] != null) {
        db.deleteProduct(p['id'] as String);
      } else {
        db.upsertProduct(p);
      }
    }
    for (final c in (ch['categories'] as List? ?? const []).cast<Map<String, dynamic>>()) {
      n++;
      db.upsertCategory(c);
    }
    for (final g in (ch['customer_groups'] as List? ?? const []).cast<Map<String, dynamic>>()) {
      n++;
      db.upsertGroup(g);
    }
    for (final c in (ch['customers'] as List? ?? const []).cast<Map<String, dynamic>>()) {
      n++;
      if (c['archived_at'] != null) {
        db.deleteCustomer(c['id'] as String);
      } else {
        db.upsertCustomer(c);
      }
    }
    for (final l in (ch['locations'] as List? ?? const []).cast<Map<String, dynamic>>()) {
      n++;
      if (l['is_active'] == false || l['is_active'] == 0) {
        db.deleteLocation(l['id'] as String);
      } else {
        db.upsertLocation(l);
      }
    }
    for (final s in (ch['stock_levels'] as List? ?? const []).cast<Map<String, dynamic>>()) {
      n++;
      db.upsertStockLevel(s['product_id'] as String, s['location_id'] as String, (s['quantity'] as num).toInt());
    }
    for (final s in (ch['settings'] as List? ?? const []).cast<Map<String, dynamic>>()) {
      n++;
      db.setMetaJson('settings', s);
    }
    final ref = res['reference_ids'];
    if (ref is Map) {
      if (ref['categories'] is List) db.pruneToIds('categories', (ref['categories'] as List).cast<String>());
      if (ref['customer_groups'] is List) db.pruneToIds('customer_groups', (ref['customer_groups'] as List).cast<String>());
    }
    return n;
  }
}
