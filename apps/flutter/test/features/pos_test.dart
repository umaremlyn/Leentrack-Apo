import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:leentrack/data/local_db.dart';
import 'package:leentrack/features/pos/cart.dart';
import 'package:leentrack/features/pos/pos_service.dart';

void main() {
  late LocalDb db;
  late PosService pos;
  var n = 0;
  final now = DateTime.utc(2026, 3, 1, 9);

  setUp(() {
    db = LocalDb.memory();
    n = 0;
    pos = PosService(db: db, newId: () => 'id-${++n}', clock: () => now);
    db.upsertLocation({'id': 'L', 'name': 'Main', 'is_default': true});
    db.upsertProduct({'id': 'p1', 'name': 'Soap', 'item_type': 'product', 'selling_price': '10.00', 'is_active': true});
    db.upsertProduct({'id': 'sv', 'name': 'Delivery', 'item_type': 'service', 'selling_price': '5.00', 'is_active': true});
    db.upsertStockLevel('p1', 'L', 5);
  });
  tearDown(() => db.close());

  Cart cart({CartSettings s = const CartSettings(taxRateBp: 750)}) => Cart(s);
  String? addSoap(Cart c, int q) => c.add(productId: 'p1', name: 'Soap', unitPrice: 1000, tracksStock: true, available: db.available('p1', 'L'), quantity: q);

  test('totals: subtotal, 7.5% tax and total in minor units', () {
    final c = cart();
    addSoap(c, 2);
    expect(c.subtotal, 2000);
    expect(c.totals.tax, 150);
    expect(c.total, 2150);
  });

  test('cannot put more in the cart than the device can still promise', () {
    final c = cart();
    expect(addSoap(c, 5), isNull);
    expect(addSoap(c, 1), 'Only 5 of Soap available.');
    expect(c.quantityOf('p1'), 5);
  });

  test('services ignore stock', () {
    final c = cart();
    expect(c.add(productId: 'sv', name: 'Delivery', unitPrice: 500, tracksStock: false, available: 0, quantity: 99), isNull);
  });

  test('checkout queues exactly what happened, quotes the total, and reserves the stock', () {
    final c = cart();
    addSoap(c, 2);
    c.add(productId: 'sv', name: 'Delivery', unitPrice: 500, tracksStock: false, available: 0);
    final sale = pos.checkout(c, locationId: 'L', credit: false, method: 'cash', amount: 3000);
    expect(sale.total, 2688); // (2000+500)=2500, tax 187.5 -> 188 (half up) => 2688
    expect(sale.change, 312);
    final op = db.outbox().single;
    expect(op['type'], 'sale.checkout');
    expect(op['id'], sale.operationId);
    final p = jsonDecode(op['payload'] as String) as Map<String, dynamic>;
    expect(p['expected_total'], '26.88');
    expect(p['payment'], {'mode': 'full', 'method': 'cash', 'amount': '30.00'});
    expect(p['items'], [{'product_id': 'p1', 'quantity': 2}, {'product_id': 'sv', 'quantity': 1}]);
    expect(p.containsKey('total'), isFalse);
    expect(jsonEncode(p).contains('unit_price'), isFalse, reason: 'prices are never sent as facts');
    expect(db.available('p1', 'L'), 3, reason: 'the same device cannot sell the same unit twice while offline');
  });

  test('a second sale cannot use stock the first one already promised', () {
    final c1 = cart();
    addSoap(c1, 4);
    pos.checkout(c1, locationId: 'L', credit: false, method: 'cash', amount: 100000);
    final c2 = cart();
    expect(addSoap(c2, 2), 'Only 1 of Soap available.');
    // and even if the screen were stale, checkout re-checks
    final c3 = cart();
    c3.add(productId: 'p1', name: 'Soap', unitPrice: 1000, tracksStock: true, available: 99, quantity: 2);
    expect(() => pos.checkout(c3, locationId: 'L', credit: false, method: 'cash', amount: 100000), throwsA(isA<CheckoutError>()));
    expect(db.outbox().length, 1);
  });

  test('refuses an empty cart, short payment, credit without customer, and over-payment on credit — queueing nothing', () {
    expect(() => pos.checkout(cart(), locationId: 'L', credit: false, method: 'cash', amount: 0), throwsA(isA<CheckoutError>()));
    final c = cart();
    addSoap(c, 1);
    expect(() => pos.checkout(c, locationId: 'L', credit: false, method: 'cash', amount: 1074), throwsA(isA<CheckoutError>()));
    expect(() => pos.checkout(c, locationId: 'L', credit: true, method: 'cash', amount: 0), throwsA(isA<CheckoutError>()));
    c.customerId = 'cust';
    expect(() => pos.checkout(c, locationId: 'L', credit: true, method: 'cash', amount: 2000), throwsA(isA<CheckoutError>()));
    expect(db.outbox(), isEmpty);
    final ok = pos.checkout(c, locationId: 'L', credit: true, method: 'cash', amount: 500);
    expect(ok.change, 0);
    final p = jsonDecode(db.outbox().single['payload'] as String) as Map;
    expect(p['payment'], {'mode': 'credit', 'method': 'cash', 'amount': '5.00'});
    expect(p['customer_id'], 'cust');
  });

  test('group discount and manual discount flow into the quoted total, inputs (not amounts) go to the server', () {
    final c = cart(s: const CartSettings(taxRateBp: 0));
    addSoap(c, 2);
    c.customerId = 'cust';
    c.groupPercent = 10;
    c.manualDiscount = 100;
    expect(c.discounts.groupDiscount, 200);
    expect(c.total, 1700);
    pos.checkout(c, locationId: 'L', credit: false, method: 'cash', amount: 1700);
    final p = jsonDecode(db.outbox().single['payload'] as String) as Map;
    expect(p['discount'], {'manual': '1.00', 'apply_group': true});
    expect(p['expected_total'], '17.00');
  });

  test('a discount that would exceed the cart is refused', () {
    final c = cart(s: const CartSettings());
    addSoap(c, 1);
    c.manualDiscount = 1000;
    expect(() => pos.checkout(c, locationId: 'L', credit: false, method: 'cash', amount: 0), throwsA(isA<CheckoutError>()));
  });

  test('settings from a pull: tax rate and loyalty are read, junk falls back to none', () {
    final s = CartSettings.fromSettings({'tax_rate': '7.50', 'currency': 'NGN', 'loyalty': {'enabled': true, 'point_value': '2.00'}});
    expect((s.taxRateBp, s.loyaltyEnabled, s.pointValue), (750, true, 200));
    final j = CartSettings.fromSettings({'tax_rate': 'abc', 'loyalty': {'enabled': false, 'point_value': null}});
    expect((j.taxRateBp, j.pointValue), (0, 0));
  });

  test('offline customer: stored locally and queued together; the sale can reference the new id', () {
    final id = pos.createCustomer(name: '  Ada Obi ', phone: '0803');
    expect(db.customers().single['local_only'], 1);
    final q = db.outbox().single;
    expect(q['type'], 'customer.create');
    expect(jsonDecode(q['payload'] as String), {'id': id, 'name': 'Ada Obi', 'phone': '0803'});
    expect(() => pos.createCustomer(name: '  '), throwsA(isA<CheckoutError>()));
    expect(db.outbox().length, 1);
  });

  test('tx is re-entrant and a failure in the inner part rolls back the whole', () {
    expect(() => db.tx(() {
          pos.createCustomer(name: 'X');
          throw StateError('later step failed');
        }), throwsStateError);
    expect(db.customers(), isEmpty);
    expect(db.outbox(), isEmpty);
  });
}
