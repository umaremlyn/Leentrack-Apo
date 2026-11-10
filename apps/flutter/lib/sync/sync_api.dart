import '../auth/authenticated_client.dart';

/// The two calls the engine needs. An interface so tests can stand in for the server.
abstract class SyncApi {
  Future<Map<String, dynamic>> push({required String deviceId, required DateTime deviceTime, required List<Map<String, dynamic>> operations});
  Future<Map<String, dynamic>> pull({required Map<String, String> cursors, int limit = 500});
}

class HttpSyncApi implements SyncApi {
  HttpSyncApi(this.client);
  final AuthenticatedClient client;

  @override
  Future<Map<String, dynamic>> push({required String deviceId, required DateTime deviceTime, required List<Map<String, dynamic>> operations}) async {
    final r = await client.post('/sync/push', body: {
      'device_id': deviceId,
      'device_time': deviceTime.toUtc().toIso8601String(),
      'operations': operations,
    });
    return r.data as Map<String, dynamic>;
  }

  @override
  Future<Map<String, dynamic>> pull({required Map<String, String> cursors, int limit = 500}) async {
    final r = await client.post('/sync/pull', body: {if (cursors.isNotEmpty) 'cursors': cursors, 'limit': limit});
    return r.data as Map<String, dynamic>;
  }
}
