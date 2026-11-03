import 'package:flutter_test/flutter_test.dart';
import 'package:leentrack/data/local_db.dart';

void main() {
  late LocalDb db;
  setUp(() => db = LocalDb.memory());
  tearDown(() => db.close());

  Map<String, dynamic> prod(String id, {String price = '12.50', bool active = true}) =>
      {'id': id, 'name': 'Item $id', 'sku': 'S$id', 'barcode': null, 'category_id': null, 'item_type': 'product', 'selling_price': price, 'reorder_level': 5, 'is_active': active};

  test('migrations run once and record the schema version', () {
    expect(db.schemaVersion, LocalDb.migrations.length);
  });

  test('prices are stored as integer minor units and upsert is idempotent', () {
    db.upsertProduct(prod('a'));
    db.upsertProduct(prod('a', price: '13.05'));
    expect(db.products().length, 1);
    expect(db.product('a')!['selling_price'], 1305);
  });

  test('cost price is null when the server did not send it', () {
    db.upsertProduct(prod('a'));
    expect(db.product('a')!['cost_price'], isNull);
    db.upsertProduct({...prod('a'), 'cost_price': '7.10'});
    expect(db.product('a')!['cost_price'], 710);
  });

  test('search treats % and _ literally', () {
    db.upsertProduct({...prod('a'), 'name': '100% Juice'});
    db.upsertProduct({...prod('b'), 'name': 'Plain'});
    expect(db.products(search: '100%').map((r) => r['id']), ['a']);
    expect(db.products(search: '_').length, 0);
  });

  test('inactive products are hidden; deleting a product removes its stock', () {
    db.upsertProduct(prod('a', active: false));
    expect(db.products(), isEmpty);
    db.upsertProduct(prod('b'));
    db.upsertStockLevel('b', 'L1', 4);
    db.deleteProduct('b');
    expect(db.available('b', 'L1'), 0);
  });

  test('available = server quantity minus what queued sales already promised', () {
    db.upsertStockLevel('p', 'L', 10);
    db.enqueue(id: 'op1', type: 'sale.checkout', payload: {}, summary: 's', now: DateTime.utc(2026, 1, 1), stock: [(productId: 'p', locationId: 'L', quantity: 3)]);
    db.enqueue(id: 'op2', type: 'sale.checkout', payload: {}, summary: 's', now: DateTime.utc(2026, 1, 1), stock: [(productId: 'p', locationId: 'L', quantity: 2)]);
    expect(db.available('p', 'L'), 5);
    db.setOperationOutcome('op1', status: 'rejected', code: 'x');
    expect(db.available('p', 'L'), 8, reason: 'a rejected sale no longer promises stock');
  });

  test('operations come back in the order they were made; backed-off ones are not due', () {
    final t = DateTime.utc(2026, 1, 1, 12);
    for (final id in ['a', 'b', 'c']) {
      db.enqueue(id: id, type: 'expense.create', payload: {'id': id}, summary: id, now: t);
    }
    db.setOperationOutcome('b', status: 'conflict', code: 'x', nextAttempt: t.add(const Duration(minutes: 5)));
    expect(db.dueOperations(t).map((r) => r['id']), ['a', 'c']);
    expect(db.dueOperations(t.add(const Duration(minutes: 6))).map((r) => r['id']), ['a', 'b', 'c']);
  });

  test('purgeApplied forgets applied operations and their promises but keeps conflicts', () {
    final t = DateTime.utc(2026, 1, 1);
    db.upsertStockLevel('p', 'L', 10);
    db.enqueue(id: 'ok', type: 'sale.checkout', payload: {}, summary: '', now: t, stock: [(productId: 'p', locationId: 'L', quantity: 1)]);
    db.enqueue(id: 'bad', type: 'sale.checkout', payload: {}, summary: '', now: t, stock: [(productId: 'p', locationId: 'L', quantity: 2)]);
    db.setOperationOutcome('ok', status: 'applied');
    db.setOperationOutcome('bad', status: 'conflict', code: 'insufficient_stock');
    db.purgeApplied();
    expect(db.outbox().map((r) => r['id']), ['bad']);
    expect(db.available('p', 'L'), 8);
  });

  test('a customer made on this device is flagged until the server version arrives', () {
    db.insertLocalCustomer(id: 'c1', name: 'Ada');
    expect(db.customers().single['local_only'], 1);
    db.upsertCustomer({'id': 'c1', 'name': 'Ada', 'loyalty_points': 0, 'total_spent': '0.00'});
    expect(db.customers().single['local_only'], 0);
  });

  test('categories and groups missing from the server list are pruned', () {
    db.upsertCategory({'id': 'x', 'name': 'X'});
    db.upsertCategory({'id': 'y', 'name': 'Y'});
    db.pruneToIds('categories', ['y']);
    expect(db.raw.select('SELECT id FROM categories').map((r) => r['id']), ['y']);
    db.pruneToIds('categories', []);
    expect(db.raw.select('SELECT id FROM categories'), isEmpty);
  });

  test('a failed transaction leaves nothing behind', () {
    expect(() => db.tx(() {
          db.upsertProduct(prod('a'));
          throw StateError('boom');
        }), throwsStateError);
    expect(db.products(), isEmpty);
  });
}
