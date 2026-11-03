import 'dart:convert';

import 'package:dio/dio.dart';

import 'api_exception.dart';

/// Thin client over the LeenTrack envelope: `{ok,data,meta}` / `{ok:false,error:{code,message,fields}}`.
/// Authentication, business context and token refresh live one level up, in [AuthenticatedClient].
class ApiClient {
  ApiClient({required String baseUrl, Dio? dio})
      : _dio = dio ??
            Dio(BaseOptions(
              baseUrl: baseUrl,
              connectTimeout: const Duration(seconds: 10),
              receiveTimeout: const Duration(seconds: 20),
              contentType: Headers.jsonContentType,
              responseType: ResponseType.plain, // decoded below so the HTTP status survives malformed bodies
              // We interpret every status ourselves so error envelopes are never lost.
              validateStatus: (_) => true,
            ));

  final Dio _dio;

  Future<ApiResult> get(String path, {Map<String, dynamic>? query, Map<String, String>? headers}) => request('GET', path, query: query, headers: headers);

  Future<ApiResult> post(String path, {Object? body, Map<String, String>? headers}) => request('POST', path, body: body, headers: headers);

  /// Any method, with optional extra headers (authentication and business context are added by [AuthenticatedClient]).
  Future<ApiResult> request(String method, String path, {Object? body, Map<String, dynamic>? query, Map<String, String>? headers}) =>
      _send(() => _dio.request<dynamic>(path, data: body, queryParameters: query, options: Options(method: method, headers: headers)));

  /// Downloads a binary body (e.g. a PDF). Error answers are still the JSON envelope and become [ApiException]s.
  Future<List<int>> getBytes(String path, {Map<String, dynamic>? query, Map<String, String>? headers}) async {
    final Response<dynamic> res;
    try {
      res = await _dio.request<dynamic>(path, queryParameters: query, options: Options(method: 'GET', headers: headers, responseType: ResponseType.bytes));
    } on DioException catch (e) {
      if (e.type == DioExceptionType.badResponse || e.response != null) {
        throw ApiException(code: 'bad_response', message: 'Unexpected response from server.', status: e.response?.statusCode);
      }
      throw const ApiException(code: 'network_error', message: 'Cannot reach the server.');
    }
    final bytes = (res.data as List<int>?) ?? const <int>[];
    if ((res.statusCode ?? 0) >= 400) {
      try {
        final j = jsonDecode(utf8.decode(bytes)) as Map<String, dynamic>;
        final err = Map<String, dynamic>.from(j['error'] as Map);
        throw ApiException(code: (err['code'] as String?) ?? 'error', message: (err['message'] as String?) ?? 'Request failed.', status: res.statusCode);
      } on FormatException {
        throw ApiException(code: 'bad_response', message: 'Unexpected response from server.', status: res.statusCode);
      }
    }
    return bytes;
  }

  Future<ApiResult> _send(Future<Response<dynamic>> Function() call) async {
    final Response<dynamic> res;
    try {
      res = await call();
    } on DioException catch (e) {
      const transport = {
        DioExceptionType.connectionTimeout,
        DioExceptionType.sendTimeout,
        DioExceptionType.receiveTimeout,
        DioExceptionType.connectionError,
        DioExceptionType.cancel,
      };
      if (transport.contains(e.type)) {
        throw const ApiException(code: 'network_error', message: 'Cannot reach the server.');
      }
      // The server answered, but not with something we can decode (e.g. HTML error page labelled as JSON).
      throw ApiException(code: 'bad_response', message: 'Unexpected response from server.', status: e.response?.statusCode);
    }
    dynamic body;
    try {
      body = res.data is String ? jsonDecode(res.data as String) : res.data;
    } on FormatException {
      body = null;
    }
    if (body is! Map<String, dynamic> || body['ok'] is! bool) {
      throw ApiException(code: 'bad_response', message: 'Unexpected response from server.', status: res.statusCode);
    }
    final meta = (body['meta'] is Map) ? Map<String, dynamic>.from(body['meta'] as Map) : <String, dynamic>{};
    if (body['ok'] == true) {
      return ApiResult(data: body['data'], meta: meta, status: res.statusCode ?? 200);
    }
    final err = (body['error'] is Map) ? Map<String, dynamic>.from(body['error'] as Map) : <String, dynamic>{};
    final rawFields = err['fields'];
    final fields = <String, List<String>>{};
    if (rawFields is Map) {
      rawFields.forEach((k, v) => fields['$k'] = (v is List) ? v.map((e) => '$e').toList() : ['$v']);
    }
    throw ApiException(
      code: (err['code'] as String?) ?? 'error',
      message: (err['message'] as String?) ?? 'Request failed.',
      status: res.statusCode,
      fields: fields,
      requestId: meta['request_id'] as String?,
    );
  }
}

class ApiResult {
  const ApiResult({required this.data, required this.meta, required this.status});
  final dynamic data;
  final Map<String, dynamic> meta;
  final int status;
}
