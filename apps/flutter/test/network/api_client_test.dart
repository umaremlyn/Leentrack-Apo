import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:leentrack/network/api_client.dart';
import 'package:leentrack/network/api_exception.dart';

/// A Dio adapter that replays a canned HTTP response — a transport stub, the ApiClient code under test is real.
class StubAdapter implements HttpClientAdapter {
  StubAdapter(this.status, this.body, {this.throwNetwork = false});
  final int status;
  final String body;
  final bool throwNetwork;

  @override
  Future<ResponseBody> fetch(RequestOptions o, Stream<Uint8List>? s, Future<void>? c) async {
    if (throwNetwork) throw DioException(requestOptions: o, type: DioExceptionType.connectionError);
    return ResponseBody.fromString(body, status, headers: {Headers.contentTypeHeader: ['application/json']});
  }

  @override
  void close({bool force = false}) {}
}

ApiClient client(StubAdapter s) {
  final dio = Dio(BaseOptions(baseUrl: 'http://x/api/v1', responseType: ResponseType.plain, validateStatus: (_) => true))..httpClientAdapter = s;
  return ApiClient(baseUrl: 'http://x/api/v1', dio: dio);
}

void main() {
  test('parses success envelope', () async {
    final r = await client(StubAdapter(200, jsonEncode({'ok': true, 'data': {'a': 1}, 'meta': {'request_id': 'r1'}}))).get('/health');
    expect((r.data as Map)['a'], 1);
    expect(r.meta['request_id'], 'r1');
  });

  test('maps error envelope incl. validation fields and request id', () async {
    final body = jsonEncode({
      'ok': false,
      'error': {'code': 'validation_failed', 'message': 'bad', 'fields': {'email': ['invalid']}},
      'meta': {'request_id': 'r2'},
    });
    try {
      await client(StubAdapter(422, body)).post('/x', body: {});
      fail('should throw');
    } on ApiException catch (e) {
      expect(e.code, 'validation_failed');
      expect(e.status, 422);
      expect(e.fields['email'], ['invalid']);
      expect(e.requestId, 'r2');
    }
  });

  test('401 is flagged unauthenticated', () async {
    try {
      await client(StubAdapter(401, jsonEncode({'ok': false, 'error': {'code': 'unauthenticated', 'message': 'no'}, 'meta': {}}))).get('/me');
      fail('should throw');
    } on ApiException catch (e) {
      expect(e.isUnauthenticated, isTrue);
    }
  });

  test('non-envelope body (e.g. HTML error page) becomes bad_response, not a crash', () async {
    try {
      await client(StubAdapter(502, '<html>bad gateway</html>')).get('/health');
      fail('should throw');
    } on ApiException catch (e) {
      expect(e.code, 'bad_response');
      expect(e.status, 502);
    }
  });

  test('transport failure becomes network_error', () async {
    try {
      await client(StubAdapter(0, '', throwNetwork: true)).get('/health');
      fail('should throw');
    } on ApiException catch (e) {
      expect(e.isNetwork, isTrue);
    }
  });
}
