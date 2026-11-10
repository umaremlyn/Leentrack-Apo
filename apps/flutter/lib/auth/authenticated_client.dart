import 'dart:async';

import '../network/api_client.dart';
import '../network/api_exception.dart';
import 'token_store.dart';

/// Adds the bearer token and the business to every call, and renews an expired access token ONCE per expiry no matter how many requests
/// notice at the same moment (single flight). The refresh token is rotated by the server on each use and is the only thing that is persisted;
/// the short-lived access token lives in memory only.
class AuthenticatedClient {
  AuthenticatedClient({required this.api, required this.store, this.onSessionExpired});

  final ApiClient api;
  final TokenStore store;

  /// Called when even the refresh token was refused: the user must sign in again.
  final void Function()? onSessionExpired;

  String? _access;
  String? businessId;
  Future<bool>? _refreshing;

  bool get hasAccessToken => _access != null;

  void setSession({required String accessToken, String? business}) {
    _access = accessToken;
    if (business != null) businessId = business;
  }

  void clearSession() {
    _access = null;
    businessId = null;
  }

  Future<ApiResult> get(String path, {Map<String, dynamic>? query}) => send('GET', path, query: query);
  Future<ApiResult> post(String path, {Object? body}) => send('POST', path, body: body);

  Future<ApiResult> send(String method, String path, {Object? body, Map<String, dynamic>? query, bool needsBusiness = true}) async {
    try {
      return await _once(method, path, body, query, needsBusiness);
    } on ApiException catch (e) {
      if (!_looksExpired(e)) rethrow;
      if (!await _refresh()) rethrow;
      return _once(method, path, body, query, needsBusiness);
    }
  }

  Future<List<int>> getBytes(String path, {Map<String, dynamic>? query}) async {
    Future<List<int>> once() => api.getBytes(path, query: query, headers: {
          'Authorization': ?(_access == null ? null : 'Bearer $_access'),
          'X-Business-Id': ?businessId,
        });
    try {
      return await once();
    } on ApiException catch (e) {
      if (!_looksExpired(e) || !await _refresh()) rethrow;
      return once();
    }
  }

  Future<ApiResult> _once(String method, String path, Object? body, Map<String, dynamic>? query, bool needsBusiness) {
    final h = <String, String>{};
    if (_access != null) h['Authorization'] = 'Bearer $_access';
    if (needsBusiness && businessId != null) h['X-Business-Id'] = businessId!;
    return api.request(method, path, body: body, query: query, headers: h);
  }

  bool _looksExpired(ApiException e) => e.status == 401 && (e.code == 'token_invalid' || e.code == 'token_expired' || e.code == 'unauthenticated');

  Future<bool> _refresh() => _refreshing ??= _doRefresh().whenComplete(() => _refreshing = null);

  Future<bool> _doRefresh() async {
    final rt = await store.readRefreshToken();
    if (rt == null) {
      _expired();
      return false;
    }
    try {
      final r = await api.post('/auth/refresh', body: {'refresh_token': rt});
      final d = r.data as Map<String, dynamic>;
      _access = d['access_token'] as String;
      await store.writeRefreshToken(d['refresh_token'] as String);
      return true;
    } on ApiException catch (e) {
      if (e.isNetwork) return false; // offline is not "signed out"
      _expired();
      return false;
    }
  }

  void _expired() {
    _access = null;
    onSessionExpired?.call();
  }
}
