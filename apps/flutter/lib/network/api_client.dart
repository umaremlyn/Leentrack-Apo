import 'dart:convert';

import 'package:dio/dio.dart';

import 'api_exception.dart';

/// Thin client over the LeenTrack envelope: `{ok,data,meta}` / `{ok:false,error:{code,message,fields}}`.
/// Auth headers, business context and token refresh are added in Phase 2 via interceptors.
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

  Future<ApiResult> get(String path, {Map<String, dynamic>? query}) =>
      _send(() => _dio.get<dynamic>(path, queryParameters: query));

  Future<ApiResult> post(String path, {Object? body}) =>
      _send(() => _dio.post<dynamic>(path, data: body));

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
