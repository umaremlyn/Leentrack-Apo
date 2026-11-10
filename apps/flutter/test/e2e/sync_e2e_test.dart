@Tags(['e2e'])
library;

import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:leentrack/auth/auth_service.dart';
import 'package:leentrack/auth/authenticated_client.dart';
import 'package:leentrack/auth/token_store.dart';
import 'package:leentrack/core/money.dart';
import 'package:leentrack/data/local_db.dart';
import 'package:leentrack/features/pos/cart.dart';
import 'package:leentrack/features/pos/pos_service.dart';
import 'package:leentrack/network/api_client.dart';
import 'package:leentrack/network/api_exception.dart';
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

  test('two tills sell the last unit: the offline sale is still recorded (owner decision G-78), stock goes to -1, nothing waits for a manager', () async {
    await engine.run(); // device sees Rice = 1
    final other = await data(owner.post('/sales', body: {
      'client_operation_id': uuid.v4(),
      'outlet_id': locationId,
      'items': [{'product_id': pB, 'quantity': 1}],
      'payment': {'mode': 'full', 'method': 'cash', 'amount': '250.50'},
    }));
    expect(other['id'], isNotNull);
    final before = (await serverSales()).length;
    final c = cartFor(db.product(pB)!, 1); // this device still believes there is one
    pos.checkout(c, locationId: locationId, credit: false, method: 'cash', amount: 25050);
    final r = await engine.run();
    expect(r.applied, 1);
    expect(r.conflicts, 0);
    expect(db.outbox(), isEmpty);
    expect((await serverSales()).length, before + 1);
    expect(db.available(pB, locationId), -1, reason: 'the pull shows the real, negative shelf count');
    // a manager counts the shelf and corrects it
    await owner.post('/stock/adjustments', body: {'product_id': pB, 'location_id': locationId, 'delta': 1, 'reason': 'correction'});
    await engine.run();
    expect(db.available(pB, locationId), 0);
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

  test('credit, repayment (retry-safe), expenses, reports and dashboard work end to end with the fields the screens read', () async {
    final cust = await data(owner.post('/customers', body: {'name': 'Debtor One'}));
    final sale = await data(owner.post('/sales', body: {
      'client_operation_id': uuid.v4(),
      'outlet_id': locationId,
      'customer_id': cust['id'],
      'items': [{'product_id': pA, 'quantity': 1}],   // earlier tests used up most of the stock; the online till refuses to oversell
      'payment': {'mode': 'credit', 'method': 'cash', 'amount': '10'},
    }));
    final credit = await owner.get('/credit', query: {'per_page': 100});
    final debtor = (credit.data as List).cast<Map<String, dynamic>>().firstWhere((c) => c['id'] == cust['id']);
    final debt = (debtor['sales'] as List).cast<Map<String, dynamic>>().single;
    expect(debt['id'], sale['id']);
    for (final k in ['receipt_number', 'total_amount', 'balance_due', 'days_old']) {
      expect(debt.containsKey(k), isTrue, reason: 'the credit screen reads $k');
    }
    expect(credit.meta['summary'], contains('total_owed'));
    final before = Cents.parse('${debt['balance_due']}');

    final opId = uuid.v4();
    final body = {'amount': '50.00', 'method': 'cash', 'client_operation_id': opId};
    await owner.post('/sales/${sale['id']}/repayments', body: body);
    await owner.post('/sales/${sale['id']}/repayments', body: body); // the same press sent twice
    final after = ((await owner.get('/credit')).data as List).cast<Map<String, dynamic>>().firstWhere((c) => c['id'] == cust['id']);
    expect(Cents.parse('${(after['sales'] as List).single['balance_due']}'), before - 5000, reason: 'one repayment, not two');

    final exp = await data(owner.post('/expenses', body: {'description': 'Diesel', 'amount': '1500.50', 'expense_date': DateTime.now().toIso8601String().substring(0, 10)}));
    final list = await owner.get('/expenses', query: {'per_page': 100});
    expect((list.data as List).cast<Map<String, dynamic>>().where((e) => e['id'] == exp['id']), isNotEmpty);
    expect(list.meta['summary'], allOf(contains('total'), contains('count')));
    await owner.send('DELETE', '/expenses/${exp['id']}');
    expect(((await owner.get('/expenses')).data as List).cast<Map<String, dynamic>>().where((e) => e['id'] == exp['id']), isEmpty);

    final reports = ((await owner.get('/reports')).data as List).cast<Map<String, dynamic>>();
    expect(reports.map((r) => r['key']), containsAll(['sales', 'credit', 'expenses']));
    for (final r in reports.where((r) => r['allowed'] == true)) {
      final res = await owner.get('/reports/${r['key']}', query: {'per_page': 100, if (r['timed'] == true) 'period': 'month'});
      expect(res.data, isA<List>(), reason: '${r['key']} returns rows');
      expect(res.meta['columns'], isA<List>(), reason: '${r['key']} names its columns');
    }

    final dash = (await owner.get('/dashboard')).data as Map;
    expect((dash['metrics'] as Map).keys, containsAll(['revenue_billed', 'revenue_collected', 'expenses', 'gross_profit', 'net_profit', 'transactions']));
  });

  test('purchasing: supplier, order, place, receive (stock arrives once even if sent twice), pay, and the device sees the new stock', () async {
    final sup = await data(owner.post('/suppliers', body: {'name': 'Acme Ltd', 'phone': '0803'}));
    final po = await data(owner.post('/purchase-orders', body: {
      'supplier_id': sup['id'],
      'items': [{'product_id': pA, 'quantity': 10, 'unit_cost': '55.00'}],
    }));
    expect(po['status'], 'draft');
    await owner.post('/purchase-orders/${po['id']}/order');
    final detail = await data(owner.get('/purchase-orders/${po['id']}'));
    final item = (detail['items'] as List).cast<Map<String, dynamic>>().single;
    for (final k in ['id', 'product_name', 'quantity_ordered', 'quantity_received', 'outstanding', 'line_total']) {
      expect(item.containsKey(k), isTrue, reason: 'the order screen reads $k');
    }
    for (final k in ['order_number', 'status', 'supplier_name', 'outlet_name', 'total_cost', 'received_value', 'paid', 'balance_due']) {
      expect(detail.containsKey(k), isTrue, reason: 'the order screen reads $k');
    }
    await engine.run();
    final before = db.available(pA, locationId);
    final body = {'client_operation_id': uuid.v4(), 'items': [{'item_id': item['id'], 'quantity': 4}]};
    await owner.post('/purchase-orders/${po['id']}/receive', body: body);
    await owner.post('/purchase-orders/${po['id']}/receive', body: body); // the same press sent twice
    await engine.run();
    expect(db.available(pA, locationId), before + 4, reason: 'four units arrived once, not eight');
    final after = await data(owner.get('/purchase-orders/${po['id']}'));
    expect(after['status'], 'partially_received');
    expect(Cents.parse('${after['balance_due']}'), 22000, reason: '4 x 55.00 received, nothing paid yet');
    final payOp = uuid.v4();
    final pay = {'amount': '100.00', 'method': 'cash', 'client_operation_id': payOp};
    await owner.post('/purchase-orders/${po['id']}/payments', body: pay);
    await owner.post('/purchase-orders/${po['id']}/payments', body: pay);
    expect(Cents.parse('${(await data(owner.get('/purchase-orders/${po['id']}')))['balance_due']}'), 12000, reason: 'one payment of 100.00');
    final sups = ((await owner.get('/suppliers')).data as List).cast<Map<String, dynamic>>().firstWhere((s) => s['id'] == sup['id']);
    expect(Cents.parse('${sups['balance_owed']}'), 12000);
  });

  test('voiding a sale restores the stock the device sees, refuses a second void, and a plan without staff explains itself', () async {
    await engine.run();
    final before = db.available(pB, locationId);
    await owner.post('/stock/adjustments', body: {'product_id': pB, 'location_id': locationId, 'delta': 3, 'reason': 'found'});
    final sale = await data(owner.post('/sales', body: {
      'client_operation_id': uuid.v4(),
      'outlet_id': locationId,
      'items': [{'product_id': pB, 'quantity': 2}],
      'payment': {'mode': 'full', 'method': 'cash', 'amount': '501.00'},
    }));
    final detail = await data(owner.get('/sales/${sale['id']}'));
    for (final k in ['receipt_number', 'status', 'cashier_name', 'outlet_name', 'items', 'subtotal', 'total_amount', 'paid_so_far', 'balance_due', 'voided_at', 'void_reason']) {
      expect(detail.containsKey(k), isTrue, reason: 'the sale page reads $k');
    }
    expect((detail['items'] as List).first, allOf(contains('name'), contains('quantity'), contains('unit_price'), contains('total_price')));
    await engine.run();
    expect(db.available(pB, locationId), before + 3 - 2);
    final v = await data(owner.post('/sales/${sale['id']}/void', body: {'reason': 'Customer returned goods'}));
    expect(Cents.parse('${v['refund_due']}'), 50100);
    await engine.run();
    expect(db.available(pB, locationId), before + 3, reason: 'the two units are back on the shelf');
    await expectLater(owner.post('/sales/${sale['id']}/void', body: {'reason': 'again'}), throwsA(isA<ApiException>().having((e) => e.status, 'status', 409)));
    expect(db.available(pB, locationId), before + 3, reason: 'a second void changed nothing');
    // free plan: the server's own message is what the Staff screen shows
    await expectLater(owner.post('/staff', body: {'name': 'Cashier One', 'email': 'c1@example.test', 'role': 'cashier'}), throwsA(isA<ApiException>().having((e) => e.code, 'code', 'feature_not_in_plan').having((e) => e.message, 'message', isNotEmpty)));
    expect(((await owner.get('/staff')).data as List).single['role'], 'owner', reason: 'the team list itself is readable');
  });

  test('settings: a new tax rate and loyalty settings reach the till through sync and change its totals; alerts, password and sessions behave', () async {
    await engine.run();
    expect(CartSettings.fromSettings(db.metaJson('settings')).taxRateBp, 0);
    final biz = await data(owner.get('/businesses/$bid'));
    for (final k in ['id', 'name', 'currency', 'tax_rate', 'effective_plan']) {
      expect(biz.containsKey(k), isTrue, reason: 'the settings screen reads $k');
    }
    await owner.send('PATCH', '/businesses/$bid', body: {'name': '${biz['name']}', 'tax_rate': '7.5'});
    final loy = await data(owner.get('/businesses/$bid/loyalty-settings'));
    expect(loy.keys, containsAll(['enabled', 'spend_per_point', 'point_value']));
    await owner.send('PUT', '/businesses/$bid/loyalty-settings', body: {'enabled': true, 'spend_per_point': '100.00', 'point_value': '1.00'});
    await engine.run();
    final cs = CartSettings.fromSettings(db.metaJson('settings'));
    expect(cs.taxRateBp, 750, reason: 'the pull delivered the new tax rate');
    expect((cs.loyaltyEnabled, cs.pointValue), (true, 100));
    final cart = Cart(cs)..add(productId: pA, name: 'Soap', unitPrice: 10000, tracksStock: false, available: 0);
    expect(cart.total, 10750, reason: '100.00 + 7.5% tax');

    final alert = await data(owner.get('/businesses/$bid/alert-settings'));
    expect(alert.keys, containsAll(['enabled', 'email', 'default_email', 'frequency']));
    await owner.send('PUT', '/businesses/$bid/alert-settings', body: {'enabled': true, 'frequency': 'hourly', 'email': alert['default_email']});
    await expectLater(owner.send('PUT', '/businesses/$bid/alert-settings', body: {'enabled': true, 'frequency': 'hourly', 'email': null}), throwsA(isA<ApiException>().having((e) => e.status, 'status', 422)), reason: 'enabling alerts needs a spelled-out address (the screen pre-fills it)');
    expect((await data(owner.get('/businesses/$bid/alert-settings')))['frequency'], 'hourly');

    final sessions = ((await owner.get('/me/sessions')).data as List).cast<Map<String, dynamic>>();
    expect(sessions.where((x) => x['current'] == true), hasLength(1), reason: 'exactly one session is marked as this device');
    await expectLater(owner.post('/me/change-password', body: {'current_password': 'definitely-wrong', 'new_password': 'Another-Good-Pass-88'}), throwsA(isA<ApiException>().having((e) => e.message, 'message', isNotEmpty)));
  });
}
