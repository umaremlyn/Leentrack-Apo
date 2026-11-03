import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Where the refresh token lives. In the app: the platform keychain/keystore. In tests: memory.
abstract class TokenStore {
  Future<String?> readRefreshToken();
  Future<void> writeRefreshToken(String token);
  Future<String?> readBusinessId();
  Future<void> writeBusinessId(String id);
  /// Stable id of this installation. Created once, survives logout (it names the device, not the person).
  Future<String> deviceId(String Function() create);
  Future<void> clear();
}

class MemoryTokenStore implements TokenStore {
  String? refresh;
  String? business;
  String? device;
  @override
  Future<String> deviceId(String Function() create) async => device ??= create();
  @override
  Future<void> clear() async {
    refresh = null;
    business = null;
  }

  @override
  Future<String?> readBusinessId() async => business;
  @override
  Future<String?> readRefreshToken() async => refresh;
  @override
  Future<void> writeBusinessId(String id) async => business = id;
  @override
  Future<void> writeRefreshToken(String token) async => refresh = token;
}

class SecureTokenStore implements TokenStore {
  SecureTokenStore([FlutterSecureStorage? s]) : _s = s ?? const FlutterSecureStorage();
  final FlutterSecureStorage _s;
  static const _refresh = 'lt_refresh_token';
  static const _business = 'lt_business_id';
  static const _device = 'lt_device_id';

  @override
  Future<String> deviceId(String Function() create) async {
    final have = await _s.read(key: _device);
    if (have != null && have.isNotEmpty) return have;
    final id = create();
    await _s.write(key: _device, value: id);
    return id;
  }

  @override
  Future<void> clear() async {
    await _s.delete(key: _refresh);
    await _s.delete(key: _business);
  }

  @override
  Future<String?> readBusinessId() => _s.read(key: _business);
  @override
  Future<String?> readRefreshToken() => _s.read(key: _refresh);
  @override
  Future<void> writeBusinessId(String id) => _s.write(key: _business, value: id);
  @override
  Future<void> writeRefreshToken(String token) => _s.write(key: _refresh, value: token);
}
