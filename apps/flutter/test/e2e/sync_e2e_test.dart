@Tags(['e2e'])
library;

import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:leentrack/auth/auth_service.dart';
import 'package:leentrack/auth/authenticated_client.dart';
import 'package:leentrack/auth/token_store.dart';
import 'package:leentrack/data/local_db.dart';
import 'package:leentrack/features/pos/cart.dart';
import 'package:leentrack/features/pos/pos_service.dart';
import 'package:leentrack/network/api_client.dart';
import 'package:leentrack/sync/sync_api.dart';
import 'package:leentrack/sync/sync_engine.dart';
import 'package:uuid/uuid.dart';

/// Runs the REAL Dart client code against the REAL PHP API + MySQL started by tools/e2e/run.sh.
/// Skipped unless LT_E2E_URL is set, so a normal `flutter test` never needs a server.
void main() {
  final url = Platform.environment['LT_E2E_URL'];
  if (url == null) {
    test('e2e skipped (set LT_E2E_URL, or run tools/e2e/run.sh)', () {}, skip: 'needs a running API');
    return;
  }

  const uuid = Uuid();
  late AuthenticatedClient owner;
  late AuthService auth;
  late LocalDb db;
  late SyncEngine engine;
  late PosService pos;
  var now = DateTime.now().toUtc();
  late String bid, locationId, pA, pB;

  Future<Map<String, dynamic>> data(Future<ApiResult> f) async => ((await f).data as Map).cast<String, dynamic>();

  setUpAll(() async {
    final api = ApiClient(baseUrl: url);
    final email = 'e2e-${uuid.v4().substring(0, 8)}@example.test';
    await api.post('/auth/register', body: {'email': email, 'password': 'Correct-Horse-9', 'full_name': 'E2E Owner', 'business_name': 'E2E Shop', 'business_type': 'retail', 'currency': 'NGN'});
    final store = MemoryTokenStore();
    owner = AuthenticatedClient(api: api, store: store);
    auth = AuthService(client: owner, store: store, deviceId: 'e2e-device-1');
    final s = await auth.login(email, 'Correct-Horse-9');
    bid = s.memberships.first.businessId;
    await auth.selectBusiness(bid);
    pA = (await data(owner.post('/products', body: {'name': 'Soap', 'selling_price': '100.00', 'cost_price': '60.00', 'stock_quantity': 5})))['id'] as String;
    pB = (await data(owner.post('/products', body: {'name': 'Rice', 'selling_price': '250.50', 'cost_price': '200.00', 'stock_quantity': 1})))['id'] as String;
    db = LocalDb.memory();
    final sync = HttpSyncApi(owner);
    engine = SyncEngine(db: db, api: sync, deviceId: 'e2e-device-1', clock: () => now);
    pos = PosService(db: db, newId: () => uuid.v4(), clock: () => now);
  });

  Cart cartFor(Map<String, Object?> p, int q) {
    final settings = CartSettings.fromSettings(db.metaJson('settings'));
    final c = Cart(settings);
    expect(c.add(productId: p['id'] as String, name: p['name'] as String, unitPrice: p['selling_price'] as int, tracksStock: p['item_type'] == 'product', available: db.available(p['id'] as String, locationId), quantity: q), isNull);
    return c;
  }

  Future<List<Map<String, dynamic>>> serverSales() async {
    final r = await owner.get('/sales', query: {'per_page': 100});
    return (r.data as List).cast<Map<String, dynamic>>();
  }

  test('first sync downloads the catalogue, locations, stock and settings', () async {
    final r = await engine.run();
    expect(r.failure, isNull);
    expect(db.products().map((p) => p['name']), containsAll(['Soap', 'Rice']));
    expect(db.product(pA)!['selling_price'], 10000);
    expect(db.product(pB)!['selling_price'], 25050);
    locationId = db.locations().firstWhere((l) => l['is_default'] == 1)['id'] as String;
    expect(db.available(pA, locationId), 5);
    expect(db.available(pB, locationId), 1);
    expect(db.metaJson('settings')['currency'], 'NGN');
    expect(db.product(pA)!['cost_price'], 6000, reason: 'the owner may read costs');
  });

  test('an offline sale and a new customer reach the server once, with the total the till quoted', () async {
    final cust = pos.createCustomer(name: 'Ada Obi', phone: '08031234567');
    final c = cartFor(db.product(pA)!, 2)..customerId = cust;
    final sale = pos.checkout(c, locationId: locationId, credit: false, method: 'cash', amount: 20000);
    expect(sale.total, 20000);
    expect(db.available(pA, locationId), 3, reason: 'promised locally before the server knows');
    final r = await engine.run();
    expect(r.applied, 2);
    expect(r.failure, isNull);
    expect(db.outbox(), isEmpty);
    expect(db.available(pA, locationId), 3, reason: 'after the pull the server number says the same');
    expect(db.customers().single['local_only'], 0);
    final sales = await serverSales();
    expect(sales.length, 1);
    expect('${sales.single['total_amount'] ?? sales.single['total']}', startsWith('200'));
  });


  test('replaying the same operation id after a crash is answered as a duplicate and does not sell twice', () async {
    final c = cartFor(db.product(pA)!, 1);
    final sale = pos.checkout(c, locationId: locationId, credit: false, method: 'cash', amount: 10000);
    final op = Map<String, Object?>.from(db.outbox().single);
    await engine.run();
    final before = (await serverSales()).length;
    db.raw.execute('INSERT OR REPLACE INTO outbox (id, type, payload, summary, client_created_at) VALUES (?,?,?,?,?)', [op['id'], op['type'], op['payload'], op['summary'], op['client_created_at']]);
    expect(sale.operationId, op['id']);
    final r = await engine.run();
    expect(r.applied, 1, reason: 'the server says applied (duplicate)');
    expect((await serverSales()).length, before);
  });

  test('two devices racing for the last unit: the loser gets a conflict, keeps the sale, and it goes through after a restock', () async {
    await engine.run(); // device sees Rice = 1
    // another till sells the last Rice first (directly on the server)
    final other = await data(owner.post('/sales', body: {
      'client_operation_id': uuid.v4(),
      'outlet_id': locationId,
      'items': [{'product_id': pB, 'quantity': 1}],
      'payment': {'mode': 'full', 'method': 'cash', 'amount': '250.50'},
    }));
    expect(other['id'], isNotNull);
    // this device is offline and still believes there is one
    final c = cartFor(db.product(pB)!, 1);
    pos.checkout(c, locationId: locationId, credit: false, method: 'cash', amount: 25050);
    final r1 = await engine.run();
    expect(r1.conflicts, 1);
    final op = db.outbox().single;
    expect(op['status'], 'conflict');
    expect(op['code'], 'insufficient_stock');
    // a manager restocks, the device retries once the back-off has passed
    await owner.post('/stock/adjustments', body: {'product_id': pB, 'location_id': locationId, 'delta': 2, 'reason': 'found'});
    now = now.add(const Duration(minutes: 2));
    final r2 = await engine.run();
    expect(r2.applied, 1);
    expect(db.outbox(), isEmpty);
  });

  test('price rose after the quote and the cash no longer covers it: held, accept-prices refuses without destroying it, a manager discards, the device drops it', () async {
    await engine.run();
    final c = cartFor(db.product(pA)!, 1);
    pos.checkout(c, locationId: locationId, credit: false, method: 'cash', amount: 10000); // exactly the quoted 100.00
    final before = (await serverSales()).length;
    await owner.send('PATCH', '/products/$pA', body: {'selling_price': '120.00'});
    final r = await engine.run();
    expect(r.conflicts, 1);
    final op = db.outbox().single;
    expect(op['code'], 'price_changed');
    expect((await serverSales()).length, before);
    final inbox = await owner.get('/sync/operations', query: {'status': 'conflict'});
    expect((inbox.data as List).cast<Map<String, dynamic>>().where((o) => o['id'] == op['id']), isNotEmpty, reason: 'the manager sees it in the server inbox');
    await expectLater(owner.post('/sync/operations/${op['id']}/accept-prices'), throwsA(predicate((e) => e.toString().contains('payment_short'))));
    final still = await owner.get('/sync/operations', query: {'status': 'conflict'});
    expect((still.data as List).cast<Map<String, dynamic>>().where((o) => o['id'] == op['id']), isNotEmpty, reason: 'still held, not turned into a rejection');
    await owner.post('/sync/operations/${op['id']}/discard', body: {'reason': 'Customer paid the old price'});
    now = now.add(const Duration(minutes: 2));
    await engine.run();
    expect(db.outbox(), isEmpty, reason: 'a discarded operation is removed from the device');
    expect((await serverSales()).length, before);
  });

  test('price rose but the customer handed over enough: a manager accepts the new price and the sale is recorded once', () async {
    await engine.run();
    final c = cartFor(db.product(pA)!, 1);
    pos.checkout(c, locationId: locationId, credit: false, method: 'cash', amount: 20000);
    final before = (await serverSales()).length;
    await owner.send('PATCH', '/products/$pA', body: {'selling_price': '130.00'});
    final r = await engine.run();
    expect(r.conflicts, 1);
    final op = db.outbox().single;
    await owner.post('/sync/operations/${op['id']}/accept-prices');
    expect((await serverSales()).length, before + 1);
    now = now.add(const Duration(minutes: 2));
    await engine.run();
    expect(db.outbox(), isEmpty);
    expect((await serverSales()).length, before + 1, reason: 'the device retry did not sell it a second time');
    expect(db.product(pA)!['selling_price'], 13000);
  });
}
