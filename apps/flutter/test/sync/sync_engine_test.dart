import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:leentrack/data/local_db.dart';
import 'package:leentrack/network/api_exception.dart';
import 'package:leentrack/sync/sync_api.dart';
import 'package:leentrack/sync/sync_engine.dart';

/// Stand-in for the server that follows docs/14: scripted answers, and it records exactly what was sent.
class FakeApi implements SyncApi {
  final pushes = <Map<String, dynamic>>[];
  final pullCursors = <Map<String, String>>[];
  List<Map<String, dynamic>> Function(List<Map<String, dynamic>> ops) answer = (ops) => [for (final o in ops) {'id': o['id'], 'status': 'applied', 'duplicate': false, 'result': {'sale_id': 's-${o['id']}'}}];
  final pages = <Map<String, dynamic>>[];
  Object? failPush;
  Object? failPull;

  @override
  Future<Map<String, dynamic>> push({required String deviceId, required DateTime deviceTime, required List<Map<String, dynamic>> operations}) async {
    if (failPush != null) throw failPush!;
    pushes.add({'device_id': deviceId, 'device_time': deviceTime.toUtc().toIso8601String(), 'operations': operations});
    return {'results': answer(operations)};
  }

  @override
  Future<Map<String, dynamic>> pull({required Map<String, String> cursors, int limit = 500}) async {
    if (failPull != null) throw failPull!;
    pullCursors.add(Map.of(cursors));
    return pages.isEmpty ? {'changes': {}, 'cursors': cursors, 'has_more': false, 'reference_ids': []} : pages.removeAt(0);
  }
}

void main() {
  late LocalDb db;
  late FakeApi api;
  var now = DateTime.utc(2026, 3, 1, 10);
  late SyncEngine engine;

  setUp(() {
    now = DateTime.utc(2026, 3, 1, 10);
    db = LocalDb.memory();
    api = FakeApi();
    engine = SyncEngine(db: db, api: api, deviceId: 'dev-1', clock: () => now);
  });
  tearDown(() => db.close());

  void queue(String id, {String type = 'sale.checkout', int qty = 0}) => db.enqueue(
      id: id, type: type, payload: {'n': id}, summary: id, now: now, stock: qty > 0 ? [(productId: 'p', locationId: 'L', quantity: qty)] : const []);

  test('sends operations in the order they were made, with device identity and time', () async {
    queue('a');
    queue('b');
    queue('c');
    final r = await engine.run();
    expect(api.pushes.single['device_id'], 'dev-1');
    expect(api.pushes.single['device_time'], '2026-03-01T10:00:00.000Z');
    expect((api.pushes.single['operations'] as List).map((o) => o['id']), ['a', 'b', 'c']);
    expect((api.pushes.single['operations'] as List).first['payload'], {'n': 'a'});
    expect(r.applied, 3);
  });

  test('more than 100 queued operations go in batches of at most 100, in order', () async {
    for (var i = 0; i < 230; i++) {
      queue('op${i.toString().padLeft(3, '0')}');
    }
    await engine.run();
    expect(api.pushes.map((p) => (p['operations'] as List).length), [100, 100, 30]);
    final ids = api.pushes.expand((p) => (p['operations'] as List).map((o) => o['id'])).toList();
    expect(ids, List.generate(230, (i) => 'op${i.toString().padLeft(3, '0')}'));
  });

  test('applied operations are removed after a successful pull, and the receipt ids are kept until then', () async {
    queue('a', qty: 2);
    db.upsertStockLevel('p', 'L', 10);
    api.pages.add({'changes': {'stock_levels': [{'product_id': 'p', 'location_id': 'L', 'quantity': 8}]}, 'cursors': {'stock_levels': 'c1'}, 'has_more': false, 'reference_ids': []});
    await engine.run();
    expect(db.outbox(), isEmpty);
    expect(db.available('p', 'L'), 8, reason: 'the server number now includes the sale; the promise is gone');
  });

  test('a conflict stays queued with its reason, keeps its stock promise and is retried only after the back-off', () async {
    queue('a', qty: 1);
    db.upsertStockLevel('p', 'L', 1);
    api.answer = (ops) => [for (final o in ops) {'id': o['id'], 'status': 'conflict', 'code': 'insufficient_stock', 'message': 'Only 0 left'}];
    final r = await engine.run();
    expect(r.conflicts, 1);
    final op = db.outbox().single;
    expect(op['status'], 'conflict');
    expect(op['code'], 'insufficient_stock');
    expect(op['attempts'], 1);
    expect(db.available('p', 'L'), 0);
    expect(engine.state, SyncState.attention);

    await engine.run(); // immediately again: still backed off, nothing is sent
    expect(api.pushes.length, 1);
    now = now.add(const Duration(seconds: 10));
    api.answer = (ops) => [for (final o in ops) {'id': o['id'], 'status': 'applied', 'duplicate': false, 'result': {}}];
    await engine.run();
    expect(api.pushes.length, 2);
    expect(db.outbox(), isEmpty);
  });

  test('rejected operations stop being retried and release their stock promise', () async {
    queue('a', qty: 1);
    db.upsertStockLevel('p', 'L', 5);
    api.answer = (ops) => [for (final o in ops) {'id': o['id'], 'status': 'rejected', 'code': 'too_old', 'message': 'old'}];
    await engine.run();
    now = now.add(const Duration(days: 1));
    await engine.run();
    expect(api.pushes.length, 1);
    expect(db.outbox().single['status'], 'rejected');
    expect(db.available('p', 'L'), 5);
  });

  test('server error keeps the operation pending, backs off, and counts the attempt', () async {
    queue('a');
    api.answer = (ops) => [for (final o in ops) {'id': o['id'], 'status': 'error', 'code': 'server_error', 'message': 'try later'}];
    final r = await engine.run();
    expect(r.errors, 1);
    expect(db.outbox().single['status'], 'pending');
    expect(db.outbox().single['attempts'], 1);
  });

  test('an operation the server did not answer for is never assumed done', () async {
    queue('a');
    queue('b');
    api.answer = (ops) => [{'id': 'a', 'status': 'applied', 'duplicate': false, 'result': {}}];
    await engine.run();
    expect(db.outbox().map((r) => r['id']), ['b']);
    expect(db.outbox().single['status'], 'pending');
  });

  test('no network: everything stays queued untouched and the state is offline', () async {
    queue('a');
    api.failPush = const ApiException(code: 'network_error', message: 'x');
    final r = await engine.run();
    expect(r.offline, isTrue);
    expect(engine.state, SyncState.offline);
    expect(db.outbox().single['status'], 'pending');
    expect(db.outbox().single['attempts'], 0);
  });

  test('pull follows has_more with the cursors it was given and stores the final cursors only when done', () async {
    api.pages.addAll([
      {'changes': {'products': [{'id': 'p1', 'name': 'A', 'item_type': 'product', 'selling_price': '1.00', 'is_active': true}]}, 'cursors': {'products': 'c1'}, 'has_more': true, 'reference_ids': []},
      {'changes': {'products': [{'id': 'p2', 'name': 'B', 'item_type': 'product', 'selling_price': '2.00', 'is_active': true}]}, 'cursors': {'products': 'c2'}, 'has_more': false, 'reference_ids': {'categories': [], 'customer_groups': []}},
    ]);
    await engine.run();
    expect(api.pullCursors, [{}, {'products': 'c1'}]);
    expect(db.products().length, 2);
    expect(db.metaJson('cursors'), {'products': 'c2'});

    now = now.add(const Duration(minutes: 5));
    await engine.run();
    expect(api.pullCursors.last, {'products': 'c2'}, reason: 'an incremental pull resumes from the stored cursor, not from scratch');
  });

  test('an interrupted download does not move the stored cursors', () async {
    api.pages.add({'changes': {}, 'cursors': {'products': 'c1'}, 'has_more': true, 'reference_ids': []});
    // second pull throws
    var calls = 0;
    final flaky = _Wrap(api, () => ++calls == 2 ? const ApiException(code: 'network_error', message: 'x') : null);
    final e = SyncEngine(db: db, api: flaky, deviceId: 'd', clock: () => now);
    final r = await e.run();
    expect(r.offline, isTrue);
    expect(db.metaJson('cursors'), isEmpty);
  });

  test('tombstones remove products, customers and locations; missing categories are pruned', () async {
    db.upsertProduct({'id': 'p1', 'name': 'A', 'item_type': 'product', 'selling_price': '1', 'is_active': true});
    db.upsertCustomer({'id': 'c1', 'name': 'Ada', 'total_spent': '0'});
    db.upsertLocation({'id': 'L1', 'name': 'Main', 'is_default': true});
    db.upsertCategory({'id': 'k1', 'name': 'Gone'});
    api.pages.add({
      'changes': {
        'products': [{'id': 'p1', 'archived_at': '2026-03-01 00:00:00'}],
        'customers': [{'id': 'c1', 'archived_at': '2026-03-01 00:00:00'}],
        'locations': [{'id': 'L1', 'name': 'Main', 'is_active': false}],
      },
      'cursors': {},
      'has_more': false,
      'reference_ids': {'categories': ['k2'], 'customer_groups': []},
    });
    await engine.run();
    expect(db.product('p1'), isNull);
    expect(db.customers(), isEmpty);
    expect(db.locations(), isEmpty);
    expect(db.raw.select('SELECT id FROM categories'), isEmpty);
  });

  test('the same rows delivered twice (the 10 s overlap) change nothing', () async {
    final page = {'changes': {'products': [{'id': 'p1', 'name': 'A', 'item_type': 'product', 'selling_price': '1.50', 'is_active': true}]}, 'cursors': {'products': 'c'}, 'has_more': false, 'reference_ids': []};
    api.pages..add(page)..add(page);
    await engine.run();
    now = now.add(const Duration(minutes: 1));
    await engine.run();
    expect(db.products().length, 1);
    expect(db.product('p1')!['selling_price'], 150);
  });

  test('a full resync happens on the first run and again after a day, never in between', () async {
    Map<String, dynamic> page() => {'changes': {}, 'cursors': {'products': 'z'}, 'has_more': false, 'reference_ids': []};
    api.pages.add(page());
    await engine.run(); // first ever: full
    now = now.add(const Duration(hours: 1));
    api.pages.add(page());
    await engine.run(); // within a day: incremental from the stored cursor
    now = now.add(const Duration(hours: 25));
    api.pages.add(page());
    await engine.run(); // more than a day since the last FULL sync: from nothing again
    expect(api.pullCursors, [{}, {'products': 'z'}, {}]);
  });

  test('two runs started together share one run (no double sending)', () async {
    queue('a');
    final f = [engine.run(), engine.run()];
    await Future.wait(f);
    expect(api.pushes.length, 1);
  });

  test('the payload sent is exactly what was queued (no client-computed values added)', () async {
    db.enqueue(id: 'x', type: 'sale.checkout', payload: {'items': [{'product_id': 'p', 'quantity': 2}], 'expected_total': '10.00'}, summary: '', now: now);
    await engine.run();
    final sent = (api.pushes.single['operations'] as List).single as Map;
    expect(jsonEncode(sent['payload']), jsonEncode({'items': [{'product_id': 'p', 'quantity': 2}], 'expected_total': '10.00'}));
    expect(sent.keys.toSet(), {'id', 'type', 'client_created_at', 'payload'});
  });
}

class _Wrap implements SyncApi {
  _Wrap(this.inner, this.fail);
  final FakeApi inner;
  final Object? Function() fail;
  @override
  Future<Map<String, dynamic>> push({required String deviceId, required DateTime deviceTime, required List<Map<String, dynamic>> operations}) => inner.push(deviceId: deviceId, deviceTime: deviceTime, operations: operations);
  @override
  Future<Map<String, dynamic>> pull({required Map<String, String> cursors, int limit = 500}) async {
    final f = fail();
    if (f != null) throw f;
    return inner.pull(cursors: cursors, limit: limit);
  }
}
