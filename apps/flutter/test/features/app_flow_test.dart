import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart' show Override;
import 'package:flutter_test/flutter_test.dart';
import 'package:leentrack/app/providers.dart';
import 'package:flutter/services.dart';
import 'package:leentrack/auth/authenticated_client.dart';
import 'package:leentrack/features/sales/sales_screen.dart';
import 'package:leentrack/auth/token_store.dart';
import 'package:leentrack/data/local_db.dart';
import 'package:leentrack/features/pos/cart_provider.dart';
import 'package:leentrack/main.dart';
import 'package:leentrack/network/api_client.dart';

/// HTTP-level stand-in for the server. Everything above it (ApiClient, auth, engine, database, screens) is the real code.
class FakeServer implements HttpClientAdapter {
  String role = 'cashier';
  bool offline = false;
  final pushed = <Map<String, dynamic>>[];
  int stockA = 5;
  final repayments = <Map<String, dynamic>>[];
  final expensesPosted = <Map<String, dynamic>>[];
  final deleted = <String>[];
  int balance = 25000;
  final poCalls = <String>[];
  Map<String, dynamic>? lastReceive;
  String poStatus = 'ordered';

  final reportQueries = <Map<String, dynamic>>[];
  ResponseBody _ok(Object data, {Map<String, dynamic> meta = const {}}) => ResponseBody.fromString(jsonEncode({'ok': true, 'data': data, 'meta': meta}), 200, headers: {Headers.contentTypeHeader: ['application/json']});
  ResponseBody _err(int s, String code, String msg) => ResponseBody.fromString(jsonEncode({'ok': false, 'error': {'code': code, 'message': msg}}), s, headers: {Headers.contentTypeHeader: ['application/json']});

  @override
  Future<ResponseBody> fetch(RequestOptions o, Stream<Uint8List>? s, Future<void>? c) async {
    if (offline && o.path != '/auth/login') throw DioException(requestOptions: o, type: DioExceptionType.connectionError);
    switch (o.path) {
      case '/auth/login':
        if ((o.data as Map)['password'] != 'right') return _err(401, 'invalid_credentials', 'Wrong email or password.');
        return _ok({
          'access_token': 'a1',
          'refresh_token': 'r1',
          'user': {'id': 'u1', 'email': 'ada@x.com', 'full_name': 'Ada', 'must_change_password': false},
          'memberships': [{'business_id': 'b1', 'name': 'Shop One', 'role': role, 'currency': 'NGN'}],
        });
      case '/sync/push':
        final ops = ((o.data as Map)['operations'] as List).cast<Map<String, dynamic>>();
        pushed.addAll(ops);
        return _ok({'results': [for (final op in ops) {'id': op['id'], 'status': 'applied', 'duplicate': false, 'result': {}}]});
      case '/sync/pull':
        return _ok({
          'changes': {
            'products': [
              {'id': 'pa', 'name': 'Soap', 'barcode': '6001234', 'sku': 'SOAP-1', 'item_type': 'product', 'selling_price': '100.00', 'is_active': true, 'reorder_level': 2},
              {'id': 'pb', 'name': 'Delivery', 'item_type': 'service', 'selling_price': '50.00', 'is_active': true},
            ],
            'locations': [{'id': 'L', 'name': 'Main', 'is_default': true}],
            'stock_levels': [{'product_id': 'pa', 'location_id': 'L', 'quantity': stockA}],
            'settings': [{'id': 'b1', 'name': 'Shop One', 'currency': 'NGN', 'tax_rate': '0', 'loyalty': {'enabled': false}}],
          },
          'cursors': {'products': 'c'},
          'has_more': false,
          'reference_ids': {'categories': [], 'customer_groups': []},
        });
      case '/sync/operations':
        return _ok([]);
      case '/purchase-orders':
        if (o.method == 'POST') {
          poCalls.add('create');
          lastReceive = null;
          return _ok({'id': 'po2'});
        }
        return _ok([{'id': 'po1', 'order_number': 'PO-000001', 'status': poStatus, 'supplier_name': 'Acme Ltd', 'total_cost': '550.00', 'item_count': 1}], meta: {});
      case '/purchase-orders/po1':
        return _ok({
          'id': 'po1', 'order_number': 'PO-000001', 'status': poStatus, 'supplier_name': 'Acme Ltd', 'outlet_name': 'Main', 'total_cost': '550.00', 'received_value': '0.00', 'paid': '0.00', 'balance_due': '0.00',
          'items': [{'id': 'it1', 'product_name': 'Soap', 'quantity_ordered': 10, 'quantity_received': 0, 'outstanding': 10, 'line_total': '550.00'}],
        });
      case '/purchase-orders/po1/receive':
        lastReceive = o.data as Map<String, dynamic>;
        poStatus = 'partially_received';
        return _ok({'receipt_id': 'rc1'});
      case '/purchase-orders/po1/cancel':
        poCalls.add('cancel');
        poStatus = 'cancelled';
        return _ok({});
      case '/suppliers':
        if (o.method == 'POST') {
          poCalls.add('supplier:${(o.data as Map)['name']}');
          return _ok({'id': 'sp2'});
        }
        return _ok([{'id': 'sp1', 'name': 'Acme Ltd', 'phone': '0803', 'open_orders': 1, 'balance_owed': '120.00'}], meta: {});
      case '/credit':
        return _ok([
          {
            'id': 'c1', 'name': 'Ada', 'phone': null, 'total_owed': (balance / 100).toStringAsFixed(2), 'is_overdue': true,
            'sales': [{'id': 's9', 'receipt_number': 'SALE-9', 'total_amount': '300.00', 'balance_due': (balance / 100).toStringAsFixed(2), 'days_old': 12, 'repayments': []}],
          }
        ], meta: {'summary': {'total_owed': (balance / 100).toStringAsFixed(2), 'overdue_owed': (balance / 100).toStringAsFixed(2), 'customers': 1, 'open_sales': 1}});
      case '/sales/s9/repayments':
        final b = o.data as Map<String, dynamic>;
        if (repayments.any((r) => r['client_operation_id'] == b['client_operation_id'])) return _ok({'replayed': true});
        repayments.add(b);
        balance -= (double.parse('${b['amount']}') * 100).round();
        return _ok({'repayment': {'id': 'r1'}});
      case '/expenses':
        if (o.method == 'POST') {
          expensesPosted.add(o.data as Map<String, dynamic>);
          return _ok({'id': 'e2'});
        }
        return _ok([
          {'id': 'e1', 'description': 'Rent', 'amount': '5000.00', 'category_name': null, 'expense_date': '2026-11-01', 'payment_status': 'paid'}
        ], meta: {'summary': {'count': 1, 'total': '5000.00'}});
      case '/expenses/e1':
        deleted.add(o.method);
        return _ok({});
      case '/expense-categories':
        return _ok([{'id': 'k1', 'name': 'Rent & utilities'}]);
      case '/reports':
        return _ok([{'key': 'sales', 'allowed': true, 'timed': true}, {'key': 'credit', 'allowed': true, 'timed': false}, {'key': 'profit', 'allowed': false, 'timed': true}]);
      case '/reports/sales':
        reportQueries.add(Map.of(o.queryParameters));
        return _ok([{'receipt': 'SALE-1', 'total': '300.00', 'status': 'pending'}], meta: {'columns': ['receipt', 'total', 'status'], 'total': 1, 'summary': {'sales': 1, 'billed': '300.00'}});
      case '/reports/credit':
        return _ok([{'customer': 'Ada', 'total_owed': '230.00'}], meta: {'columns': ['customer', 'total_owed'], 'total': 1, 'summary': {'total_owed': '230.00'}});
      case '/dashboard':
        return _ok({'period': {'from': '2026-11-01', 'to': '2026-11-05'}, 'metrics': {'revenue_billed': '300.00', 'revenue_collected': '70.00', 'expenses': '5000.00', 'gross_profit': '120.00', 'net_profit': '-4880.00', 'transactions': 1, 'items_missing_cost': 2}});
      case '/sales':
        return _ok([
          {'id': 's1', 'receipt_number': 'R-0001', 'created_at': '2026-03-01T10:00:00.000Z', 'customer_name': null, 'total_amount': '200.00', 'payment_status': 'completed'},
        ]);
      case '/sales/s1/document':
        return ResponseBody.fromBytes(utf8.encode('%PDF-fake'), 200, headers: {Headers.contentTypeHeader: ['application/pdf']});
      case '/health':
        return _ok({'status': 'ok', 'db': 'ok', 'version': 't'});
    }
    return _err(404, 'not_found', o.path);
  }

  @override
  void close({bool force = false}) {}
}

Future<(FakeServer, ProviderContainer)> boot(WidgetTester t, {String role = 'cashier', List<Override> extra = const []}) async {
  final server = FakeServer()..role = role;
  final dio = Dio(BaseOptions(baseUrl: 'http://x', responseType: ResponseType.plain, validateStatus: (_) => true))..httpClientAdapter = server;
  final store = MemoryTokenStore();
  late ProviderContainer container;
  t.view.physicalSize = const Size(1200, 900);
  t.view.devicePixelRatio = 1;
  addTearDown(t.view.reset);
  final app = ProviderScope(
    overrides: [
      tokenStoreProvider.overrideWithValue(store),
      authClientProvider.overrideWith((ref) => AuthenticatedClient(api: ApiClient(baseUrl: 'http://x', dio: dio), store: store)),
      dbFactoryProvider.overrideWithValue((u, b) async => LocalDb.memory()),
      ...extra,
    ],
    child: Consumer(builder: (c, ref, _) {
      container = ProviderScope.containerOf(c);
      return const LeenTrackApp(configured: true);
    }),
  );
  await t.pumpWidget(app);
  await t.pumpAndSettle();
  return (server, container);
}

Future<void> signIn(WidgetTester t, {String pw = 'right'}) async {
  await t.enterText(find.byKey(const Key('login-email')), 'ada@x.com');
  await t.enterText(find.byKey(const Key('login-password')), pw);
  await t.tap(find.byKey(const Key('login-submit')));
  await t.pumpAndSettle();
}

void main() {
  testWidgets('wrong password shows the server message and stays on the login screen', (t) async {
    await boot(t);
    await signIn(t, pw: 'nope');
    expect(find.text('Wrong email or password.'), findsOneWidget);
    expect(find.byKey(const Key('login-submit')), findsOneWidget);
  });

  testWidgets('sign in, first sync fills the till, a sale is saved with a provisional receipt and sent to the server', (t) async {
    final (server, _) = await boot(t);
    await signIn(t);
    expect(find.text('Shop One · cashier'), findsOneWidget);
    expect(find.text('Soap'), findsOneWidget);
    expect(find.text('5 in stock'), findsOneWidget);

    await t.tap(find.byKey(const Key('product-pa')));
    await t.tap(find.byKey(const Key('product-pa')));
    await t.pump();
    await t.tap(find.byKey(const Key('checkout')));
    await t.pumpAndSettle();
    await t.enterText(find.byKey(const Key('pay-amount')), '300');
    await t.tap(find.byKey(const Key('pay-confirm')));
    await t.pumpAndSettle();
    expect(find.textContaining('PROVISIONAL'), findsOneWidget);
    expect(find.byKey(const Key('change')), findsOneWidget);
    expect(find.text('Change 100.00'), findsOneWidget);
    await t.tap(find.byKey(const Key('receipt-ok')));
    await t.pumpAndSettle();

    expect(server.pushed.single['type'], 'sale.checkout');
    final p = server.pushed.single['payload'] as Map;
    expect(p['expected_total'], '200.00');
    expect(p['items'], [{'product_id': 'pa', 'quantity': 2}]);
    expect(find.text('All synced'), findsOneWidget);
  });

  testWidgets('offline: the sale is still completed, stays queued, and the bar says so; stock on screen drops immediately', (t) async {
    final (server, _) = await boot(t);
    await signIn(t);
    server.offline = true;
    await t.tap(find.byKey(const Key('product-pa')));
    await t.pump();
    await t.tap(find.byKey(const Key('checkout')));
    await t.pumpAndSettle();
    await t.tap(find.byKey(const Key('pay-confirm')));
    await t.pumpAndSettle();
    await t.tap(find.byKey(const Key('receipt-ok')));
    await t.pumpAndSettle();
    expect(server.pushed, isEmpty);
    expect(find.textContaining('Offline'), findsOneWidget);
    expect(find.textContaining('1 waiting'), findsOneWidget);
    expect(find.text('4 in stock'), findsOneWidget);
  });

  testWidgets('a cashier sees only POS, Sales and Customers; no manager inbox on the sync screen', (t) async {
    await boot(t);
    await signIn(t);
    expect(find.byKey(const Key('nav-inventory')), findsNothing);
    expect(find.byKey(const Key('nav-customers')), findsOneWidget);
    await t.tap(find.byKey(const Key('sync-bar')));
    await t.pumpAndSettle();
    expect(find.text('Needs a manager (all devices)'), findsNothing);
  });

  testWidgets('an owner reaches Inventory and the manager inbox', (t) async {
    await boot(t, role: 'owner');
    await signIn(t);
    await t.tap(find.byKey(const Key('nav-inventory')));
    await t.pumpAndSettle();
    expect(find.byKey(const Key('stock-pa')), findsOneWidget);
    await t.tap(find.byKey(const Key('sync-bar')));
    await t.pumpAndSettle();
    expect(find.text('Needs a manager (all devices)'), findsOneWidget);
    expect(find.text('Nothing needs attention'), findsOneWidget);
  });

  testWidgets('a customer created at the till is saved locally, flagged, and queued', (t) async {
    final (server, c) = await boot(t);
    await signIn(t);
    server.offline = true;
    await t.tap(find.byKey(const Key('nav-customers')));
    await t.pumpAndSettle();
    await t.tap(find.byKey(const Key('add-customer')));
    await t.pumpAndSettle();
    await t.enterText(find.byKey(const Key('customer-name')), 'Ada Obi');
    await t.tap(find.byKey(const Key('customer-save')));
    await t.pumpAndSettle();
    expect(find.text('Ada Obi'), findsOneWidget);
    expect(find.text('Not synced yet'), findsOneWidget);
    expect(c.read(sessionProvider).work!.db.outbox().single['type'], 'customer.create');
  });

  testWidgets('keyboard-first: a scanner (barcode + Enter) adds the item, F9 opens payment, F4 clears the cart', (t) async {
    final (server, c) = await boot(t);
    await signIn(t);
    await t.enterText(find.byKey(const Key('pos-search')), '6001234');
    await t.testTextInput.receiveAction(TextInputAction.search);
    await t.pump();
    expect(c.read(cartProvider).cart.quantityOf('pa'), 1);
    await t.enterText(find.byKey(const Key('pos-search')), 'SOAP-1'); // SKU works too
    await t.testTextInput.receiveAction(TextInputAction.search);
    await t.pump();
    expect(c.read(cartProvider).cart.quantityOf('pa'), 2);
    await t.enterText(find.byKey(const Key('pos-search')), 'zzz');
    await t.testTextInput.receiveAction(TextInputAction.search);
    await t.pump();
    expect(find.textContaining('No product matches'), findsOneWidget);
    expect(c.read(cartProvider).cart.quantityOf('pa'), 2, reason: 'an unknown code changes nothing');

    await t.sendKeyEvent(LogicalKeyboardKey.f9);
    await t.pumpAndSettle();
    expect(find.byKey(const Key('pay-confirm')), findsOneWidget);
    await t.tap(find.text('Cancel'));
    await t.pumpAndSettle();
    await t.sendKeyEvent(LogicalKeyboardKey.f4);
    await t.pump();
    expect(c.read(cartProvider).cart.isEmpty, isTrue);
    expect(server.pushed, isEmpty);
  });

  testWidgets('sales history lists the server sales and saves the PDF receipt; offline it explains instead of failing', (t) async {
    final saved = <String, List<int>>{};
    final (server, _) = await boot(t, extra: [receiptSaverProvider.overrideWithValue((name, bytes) async { saved[name] = bytes; return '/docs/$name'; })]);
    await signIn(t);
    await t.tap(find.byKey(const Key('nav-sales')));
    await t.pumpAndSettle();
    expect(find.textContaining('R-0001'), findsOneWidget);
    await t.tap(find.byKey(const Key('receipt-s1')));
    await t.pumpAndSettle();
    expect(saved.keys.single, 'receipt-R-0001.pdf');
    expect(utf8.decode(saved.values.single), '%PDF-fake');
    expect(find.textContaining('Receipt saved'), findsOneWidget);

    server.offline = true;
    await t.tap(find.byKey(const Key('nav-pos')));
    await t.pumpAndSettle();
    await t.tap(find.byKey(const Key('nav-sales')));
    await t.pumpAndSettle();
    expect(find.textContaining('Offline'), findsWidgets);
  });

  testWidgets('credit: the owner sees who owes what and records a repayment once, with a retry-safe id; the balance then drops', (t) async {
    final (server, _) = await boot(t, role: 'owner');
    await signIn(t);
    await t.tap(find.byKey(const Key('nav-credit')));
    await t.pumpAndSettle();
    expect(find.text('Owed in total: 250.00'), findsOneWidget);
    await t.tap(find.byKey(const Key('debtor-c1')));
    await t.pumpAndSettle();
    await t.tap(find.byKey(const Key('repay-s9')));
    await t.pumpAndSettle();
    await t.enterText(find.byKey(const Key('repay-amount')), '999'); // more than owed
    await t.tap(find.byKey(const Key('repay-confirm')));
    await t.pumpAndSettle();
    expect(find.textContaining('Between 0.01'), findsOneWidget);
    expect(server.repayments, isEmpty);
    await t.enterText(find.byKey(const Key('repay-amount')), '100');
    await t.tap(find.byKey(const Key('repay-confirm')));
    await t.pumpAndSettle();
    expect(server.repayments.single['amount'], '100.00');
    expect(server.repayments.single['client_operation_id'], isNotEmpty);
    expect(find.text('Owed in total: 150.00'), findsOneWidget);
  });

  testWidgets('expenses: list, add with a category, delete on long-press with confirmation', (t) async {
    final (server, _) = await boot(t, role: 'owner');
    await signIn(t);
    await t.tap(find.byKey(const Key('nav-expenses')));
    await t.pumpAndSettle();
    expect(find.text('Total: 5,000.00'), findsOneWidget);
    await t.tap(find.byKey(const Key('add-expense')));
    await t.pumpAndSettle();
    await t.tap(find.byKey(const Key('expense-save')));
    await t.pumpAndSettle();
    expect(find.textContaining('Enter a description'), findsOneWidget);
    expect(server.expensesPosted, isEmpty);
    await t.enterText(find.byKey(const Key('expense-desc')), 'Diesel');
    await t.enterText(find.byKey(const Key('expense-amount')), '1500.50');
    await t.tap(find.byKey(const Key('expense-save')));
    await t.pumpAndSettle();
    expect(server.expensesPosted.single['description'], 'Diesel');
    expect(server.expensesPosted.single['amount'], '1500.50');
    expect(server.expensesPosted.single.containsKey('category_id'), isFalse);
    await t.longPress(find.byKey(const Key('expense-e1')));
    await t.pumpAndSettle();
    await t.tap(find.byKey(const Key('expense-delete-confirm')));
    await t.pumpAndSettle();
    expect(server.deleted, ['DELETE']);
  });

  testWidgets('reports: only allowed reports are offered; the period is sent for timed ones; columns and totals come from the server', (t) async {
    final (server, _) = await boot(t, role: 'owner');
    await signIn(t);
    await t.tap(find.byKey(const Key('nav-reports')));
    await t.pumpAndSettle();
    expect(find.byKey(const Key('report-table')), findsOneWidget);
    expect(find.text('SALE-1'), findsOneWidget);
    expect(find.text('Billed: 300.00'), findsOneWidget);
    expect(server.reportQueries.last['period'], 'month');
    await t.tap(find.byKey(const Key('report-pick')));
    await t.pumpAndSettle();
    expect(find.text('Profit'), findsNothing, reason: 'a report the plan/role may not use is not offered');
    await t.tap(find.text('Credit').last);
    await t.pumpAndSettle();
    expect(find.text('230.00'), findsOneWidget);
    expect(find.byKey(const Key('report-period')), findsNothing, reason: 'credit is not a timed report');
  });

  testWidgets('dashboard shows the server numbers and warns about items with no cost price', (t) async {
    await boot(t, role: 'owner');
    await signIn(t);
    await t.tap(find.byKey(const Key('nav-dashboard')));
    await t.pumpAndSettle();
    expect(find.byKey(const Key('tile-Net profit')), findsOneWidget);
    expect(find.text('-4,880.00'), findsOneWidget);
    expect(find.textContaining('2 sold item(s) have no cost price'), findsOneWidget);
  });

  testWidgets('offline, the online screens explain themselves and offer retry instead of failing', (t) async {
    final (server, _) = await boot(t, role: 'owner');
    await signIn(t);
    server.offline = true;
    await t.tap(find.byKey(const Key('nav-credit')));
    await t.pumpAndSettle();
    expect(find.byKey(const Key('online-error')), findsOneWidget);
    expect(find.textContaining('Offline'), findsWidgets);
    server.offline = false;
    await t.tap(find.text('Retry'));
    await t.pumpAndSettle();
    expect(find.text('Owed in total: 250.00'), findsOneWidget);
  });

  testWidgets('purchases: orders and suppliers are listed; receiving checks quantities and sends one retry-safe receipt', (t) async {
    final (server, _) = await boot(t, role: 'owner');
    await signIn(t);
    await t.tap(find.byKey(const Key('nav-purchases')));
    await t.pumpAndSettle();
    expect(find.textContaining('PO-000001 · Acme Ltd'), findsOneWidget);
    await t.tap(find.byKey(const Key('po-po1')));
    await t.pumpAndSettle();
    expect(find.text('Ordered · into Main'), findsOneWidget);
    await t.tap(find.byKey(const Key('po-receive')));
    await t.pumpAndSettle();
    await t.enterText(find.byKey(const Key('recv-it1')), '11'); // more than outstanding
    await t.tap(find.byKey(const Key('recv-confirm')));
    await t.pumpAndSettle();
    expect(find.textContaining('between 0 and what is outstanding'), findsOneWidget);
    expect(server.lastReceive, isNull);
    await t.enterText(find.byKey(const Key('recv-it1')), '4');
    await t.tap(find.byKey(const Key('recv-confirm')));
    await t.pumpAndSettle();
    expect(server.lastReceive!['items'], [{'item_id': 'it1', 'quantity': 4}]);
    expect(server.lastReceive!['client_operation_id'], isNotEmpty);
    expect(find.text('Partly received · into Main'), findsOneWidget);
    // back to the list, then the suppliers tab
    await t.pageBack();
    await t.pumpAndSettle();
    await t.tap(find.text('Suppliers'));
    await t.pump(const Duration(seconds: 7)); // let the "Stock received" message go away; it covers the button
    await t.pumpAndSettle();
    expect(find.text('Owed 120.00'), findsOneWidget);
    await t.tap(find.byKey(const Key('new-supplier')));
    await t.pumpAndSettle();
    await t.enterText(find.byKey(const Key('supplier-name')), 'Fresh Foods');
    await t.tap(find.byKey(const Key('supplier-save')));
    await t.pumpAndSettle();
    expect(server.poCalls, contains('supplier:Fresh Foods'));
  });

  testWidgets('purchases: cancelling asks first and a cashier cannot reach the screen', (t) async {
    final (server, _) = await boot(t, role: 'owner');
    await signIn(t);
    await t.tap(find.byKey(const Key('nav-purchases')));
    await t.pumpAndSettle();
    await t.tap(find.byKey(const Key('po-po1')));
    await t.pumpAndSettle();
    await t.tap(find.text('Cancel order'));
    await t.pumpAndSettle();
    expect(server.poCalls, isNot(contains('cancel')), reason: 'nothing happens until the owner confirms');
    await t.tap(find.byKey(const Key('confirm-yes')));
    await t.pumpAndSettle();
    expect(server.poCalls, contains('cancel'));
    expect(find.text('Cancelled · into Main'), findsOneWidget);
  });

  testWidgets('a cashier has no Purchases entry', (t) async {
    await boot(t, role: 'cashier');
    await signIn(t);
    expect(find.byKey(const Key('nav-purchases')), findsNothing);
  });
}
