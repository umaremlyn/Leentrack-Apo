import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:leentrack/app/providers.dart';
import 'package:leentrack/auth/authenticated_client.dart';
import 'package:leentrack/auth/token_store.dart';
import 'package:leentrack/data/local_db.dart';
import 'package:leentrack/main.dart';
import 'package:leentrack/network/api_client.dart';

/// HTTP-level stand-in for the server. Everything above it (ApiClient, auth, engine, database, screens) is the real code.
class FakeServer implements HttpClientAdapter {
  String role = 'cashier';
  bool offline = false;
  final pushed = <Map<String, dynamic>>[];
  int stockA = 5;

  ResponseBody _ok(Object data) => ResponseBody.fromString(jsonEncode({'ok': true, 'data': data, 'meta': {}}), 200, headers: {Headers.contentTypeHeader: ['application/json']});
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
              {'id': 'pa', 'name': 'Soap', 'item_type': 'product', 'selling_price': '100.00', 'is_active': true, 'reorder_level': 2},
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
      case '/health':
        return _ok({'status': 'ok', 'db': 'ok', 'version': 't'});
    }
    return _err(404, 'not_found', o.path);
  }

  @override
  void close({bool force = false}) {}
}

Future<(FakeServer, ProviderContainer)> boot(WidgetTester t, {String role = 'cashier'}) async {
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
}
