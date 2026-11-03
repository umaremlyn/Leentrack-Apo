import 'dart:io' show Platform;

import '../network/api_exception.dart';
import 'authenticated_client.dart';
import 'token_store.dart';

class Membership {
  const Membership({required this.businessId, required this.name, required this.role, required this.currency, this.permissions = const []});
  final String businessId;
  final String name;
  final String role;
  final String currency;
  final List<String> permissions;

  factory Membership.fromJson(Map<String, dynamic> j) => Membership(
        businessId: j['business_id'] as String,
        name: (j['name'] ?? '') as String,
        role: (j['role'] ?? 'cashier') as String,
        currency: (j['currency'] ?? 'NGN') as String,
        permissions: ((j['permissions'] as List?) ?? const []).map((e) => '$e').toList(),
      );
}

class SignedIn {
  const SignedIn({required this.userId, required this.email, required this.name, required this.memberships, required this.mustChangePassword});
  final String userId;
  final String email;
  final String? name;
  final List<Membership> memberships;
  final bool mustChangePassword;
}

class AuthService {
  AuthService({required this.client, required this.store, required this.deviceId});

  final AuthenticatedClient client;
  final TokenStore store;
  final String deviceId;

  static String get platformName {
    try {
      if (Platform.isAndroid) return 'android';
      if (Platform.isIOS) return 'ios';
      if (Platform.isWindows) return 'windows';
      if (Platform.isMacOS) return 'macos';
      if (Platform.isLinux) return 'linux';
    } catch (_) {}
    return 'web';
  }

  Future<SignedIn> login(String email, String password) async {
    final r = await client.send('POST', '/auth/login', needsBusiness: false, body: {
      'email': email.trim(),
      'password': password,
      'device': {'id': deviceId, 'name': 'LeenTrack $platformName', 'platform': platformName},
    });
    return _accept(r.data as Map<String, dynamic>);
  }

  /// Resumes a previous sign-in from the stored refresh token. Returns null when there is none or it was refused.
  Future<SignedIn?> resume() async {
    final rt = await store.readRefreshToken();
    if (rt == null) return null;
    try {
      final r = await client.send('POST', '/auth/refresh', needsBusiness: false, body: {'refresh_token': rt});
      final d = r.data as Map<String, dynamic>;
      client.setSession(accessToken: d['access_token'] as String);
      await store.writeRefreshToken(d['refresh_token'] as String);
      final me = await client.send('GET', '/me', needsBusiness: false);
      return _fromMe(me.data as Map<String, dynamic>);
    } on ApiException catch (e) {
      if (e.isNetwork) rethrow; // offline: the caller may still open the local data
      await store.clear();
      return null;
    }
  }

  Future<void> selectBusiness(String businessId) async {
    client.businessId = businessId;
    await store.writeBusinessId(businessId);
  }

  Future<void> logout() async {
    try {
      await client.send('POST', '/auth/logout', needsBusiness: false);
    } on ApiException {
      // signing out must work offline too
    }
    client.clearSession();
    await store.clear();
  }

  Future<SignedIn> _accept(Map<String, dynamic> d) async {
    client.setSession(accessToken: d['access_token'] as String);
    await store.writeRefreshToken(d['refresh_token'] as String);
    final u = d['user'] as Map<String, dynamic>;
    return SignedIn(
      userId: u['id'] as String,
      email: u['email'] as String,
      name: u['full_name'] as String?,
      memberships: ((d['memberships'] as List?) ?? const []).map((m) => Membership.fromJson(m as Map<String, dynamic>)).toList(),
      mustChangePassword: u['must_change_password'] == true,
    );
  }

  SignedIn _fromMe(Map<String, dynamic> d) {
    final u = d['user'] as Map<String, dynamic>;
    return SignedIn(
      userId: u['id'] as String,
      email: u['email'] as String,
      name: u['full_name'] as String?,
      memberships: ((d['memberships'] as List?) ?? const []).map((m) => Membership.fromJson(m as Map<String, dynamic>)).toList(),
      mustChangePassword: u['must_change_password'] == true,
    );
  }
}
