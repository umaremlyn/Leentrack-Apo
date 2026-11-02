import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../config/app_config.dart';
import '../../network/api_client.dart';

final apiClientProvider = Provider<ApiClient>((ref) => ApiClient(baseUrl: AppConfig.apiBaseUrl));

class ServerHealth {
  const ServerHealth({required this.status, required this.db, required this.version});
  final String status;
  final String db;
  final String version;
}

/// Real call to `GET /health` (no mock). Throws ApiException on failure so the UI can show it.
final serverHealthProvider = FutureProvider.autoDispose<ServerHealth>((ref) async {
  final res = await ref.watch(apiClientProvider).get('/health');
  final d = Map<String, dynamic>.from(res.data as Map);
  return ServerHealth(status: '${d['status']}', db: '${d['db']}', version: '${d['version']}');
});
