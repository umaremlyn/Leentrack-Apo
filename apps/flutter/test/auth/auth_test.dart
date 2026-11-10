import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:leentrack/auth/auth_service.dart';
import 'package:leentrack/auth/authenticated_client.dart';
import 'package:leentrack/auth/token_store.dart';
import 'package:leentrack/network/api_client.dart';
import 'package:leentrack/network/api_exception.dart';

/// A scriptable server at the HTTP level. The code under test (ApiClient, AuthenticatedClient, AuthService) is real.
class Server implements HttpClientAdapter {
  final log = <({String method, String path, Map<String, dynamic> headers, Object? body})>[];
  String validAccess = 'access-1';
  String validRefresh = 'refresh-1';
  int refreshes = 0;
  bool refreshRefused = false;
  bool down = false;
  Duration refreshDelay = Duration.zero;

  ResponseBody _ok(Object data) => ResponseBody.fromString(jsonEncode({'ok': true, 'data': data, 'meta': {}}), 200, headers: {Headers.contentTypeHeader: ['application/json']});
  ResponseBody _err(int s, String code) => ResponseBody.fromString(jsonEncode({'ok': false, 'error': {'code': code, 'message': code}}), s, headers: {Headers.contentTypeHeader: ['application/json']});

  @override
  Future<ResponseBody> fetch(RequestOptions o, Stream<Uint8List>? s, Future<void>? c) async {
    if (down) throw DioException(requestOptions: o, type: DioExceptionType.connectionError);
    final path = o.path;
    log.add((method: o.method, path: path, headers: Map.of(o.headers), body: o.data));
    if (path == '/auth/login') {
      final b = o.data as Map;
      if (b['password'] != 'right') return _err(401, 'invalid_credentials');
      return _ok({
        'access_token': validAccess,
        'refresh_token': validRefresh,
        'user': {'id': 'u1', 'email': b['email'], 'full_name': 'Ada', 'must_change_password': false},
        'memberships': [
          {'business_id': 'b1', 'name': 'Shop One', 'role': 'owner', 'currency': 'NGN'},
          {'business_id': 'b2', 'name': 'Shop Two', 'role': 'cashier', 'currency': 'NGN'},
        ],
      });
    }
    if (path == '/auth/refresh') {
      await Future<void>.delayed(refreshDelay);
      refreshes++;
      if (refreshRefused || (o.data as Map)['refresh_token'] != validRefresh) return _err(401, 'token_invalid');
      validAccess = 'access-${refreshes + 1}';
      validRefresh = 'refresh-${refreshes + 1}';
      return _ok({'access_token': validAccess, 'refresh_token': validRefresh});
    }
    if (o.headers['Authorization'] != 'Bearer $validAccess') return _err(401, 'token_invalid');
    if (path == '/me') {
      return _ok({
        'user': {'id': 'u1', 'email': 'a@b.c', 'full_name': 'Ada', 'must_change_password': false},
        'memberships': [{'business_id': 'b1', 'name': 'Shop One', 'role': 'owner', 'currency': 'NGN'}],
      });
    }
    if (path == '/auth/logout') return _ok({});
    return _ok({'path': path, 'business': o.headers['X-Business-Id']});
  }

  @override
  void close({bool force = false}) {}
}

void main() {
  late Server server;
  late MemoryTokenStore store;
  late AuthenticatedClient client;
  late AuthService auth;
  var expired = 0;

  setUp(() {
    server = Server();
    store = MemoryTokenStore();
    expired = 0;
    final dio = Dio(BaseOptions(baseUrl: 'http://x', responseType: ResponseType.plain, validateStatus: (_) => true))..httpClientAdapter = server;
    client = AuthenticatedClient(api: ApiClient(baseUrl: 'http://x', dio: dio), store: store, onSessionExpired: () => expired++);
    auth = AuthService(client: client, store: store, deviceId: 'dev-1');
  });

  test('login stores only the refresh token, reports memberships with roles, and sends the device', () async {
    final s = await auth.login(' ada@x.com ', 'right');
    expect(s.memberships.map((m) => (m.businessId, m.role)), [('b1', 'owner'), ('b2', 'cashier')]);
    expect(store.refresh, 'refresh-1');
    expect((server.log.single.body as Map)['email'], 'ada@x.com');
    expect(((server.log.single.body as Map)['device'] as Map)['id'], 'dev-1');
    expect(server.log.single.headers.containsKey('X-Business-Id'), isFalse, reason: 'login happens before a business is chosen');
  });

  test('wrong password surfaces the server error and stores nothing', () async {
    await expectLater(auth.login('a@b.c', 'nope'), throwsA(isA<ApiException>().having((e) => e.code, 'code', 'invalid_credentials')));
    expect(store.refresh, isNull);
    expect(client.hasAccessToken, isFalse);
  });

  test('requests carry the bearer token and the chosen business', () async {
    await auth.login('a@b.c', 'right');
    await auth.selectBusiness('b2');
    final r = await client.get('/sales');
    expect((r.data as Map)['business'], 'b2');
    expect(store.business, 'b2');
  });

  test('an expired access token is renewed transparently and the request is repeated once', () async {
    await auth.login('a@b.c', 'right');
    await auth.selectBusiness('b1');
    server.validAccess = 'rotated-elsewhere';
    final r = await client.get('/products');
    expect((r.data as Map)['path'], '/products');
    expect(server.refreshes, 1);
    expect(store.refresh, 'refresh-2', reason: 'the rotated refresh token must be persisted or the next renewal would fail');
  });

  test('ten requests that all notice the expiry cause ONE refresh (single flight)', () async {
    await auth.login('a@b.c', 'right');
    await auth.selectBusiness('b1');
    server.validAccess = 'stale';
    server.refreshDelay = const Duration(milliseconds: 30);
    final rs = await Future.wait(List.generate(10, (i) => client.get('/x$i')));
    expect(rs.length, 10);
    expect(server.refreshes, 1, reason: 'a second refresh with the already-rotated token would sign the user out');
  });

  test('a refused refresh token ends the session once, and the original error is raised', () async {
    await auth.login('a@b.c', 'right');
    server.validAccess = 'stale';
    server.refreshRefused = true;
    await expectLater(client.get('/products'), throwsA(isA<ApiException>().having((e) => e.status, 'status', 401)));
    expect(expired, 1);
    expect(client.hasAccessToken, isFalse);
  });

  test('being offline during a refresh is NOT treated as being signed out', () async {
    await auth.login('a@b.c', 'right');
    server.validAccess = 'stale';
    server.down = true;
    await expectLater(client.get('/products'), throwsA(isA<ApiException>().having((e) => e.isNetwork, 'network', true)));
    expect(expired, 0);
    expect(store.refresh, 'refresh-1');
  });

  test('resume signs in again from the stored refresh token and rotates it', () async {
    store.refresh = 'refresh-1';
    final s = await auth.resume();
    expect(s, isNotNull);
    expect(s!.memberships.single.businessId, 'b1');
    expect(store.refresh, 'refresh-2');
  });

  test('resume with nothing stored, or a refused token, returns null and clears the store', () async {
    expect(await auth.resume(), isNull);
    store.refresh = 'garbage';
    expect(await auth.resume(), isNull);
    expect(store.refresh, isNull);
  });

  test('resume while offline rethrows the network error and keeps the stored token', () async {
    store.refresh = 'refresh-1';
    server.down = true;
    await expectLater(auth.resume(), throwsA(isA<ApiException>().having((e) => e.isNetwork, 'network', true)));
    expect(store.refresh, 'refresh-1');
  });

  test('logout clears everything even when the server cannot be reached', () async {
    await auth.login('a@b.c', 'right');
    server.down = true;
    await auth.logout();
    expect(store.refresh, isNull);
    expect(client.hasAccessToken, isFalse);
  });
}
