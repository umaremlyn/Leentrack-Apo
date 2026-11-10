// ignore_for_file: avoid_print
// Manual live check (not part of the test suite): dart run tool/live_check.dart http://127.0.0.1:8099/api/v1
import 'package:leentrack/network/api_client.dart';
import 'package:leentrack/network/api_exception.dart';

Future<void> main(List<String> args) async {
  final c = ApiClient(baseUrl: args.first);
  final ok = await c.get('/health');
  print('health -> status=${ok.status} data=${ok.data} request_id=${ok.meta['request_id']}');
  try {
    await c.get('/nope');
  } on ApiException catch (e) {
    print('404 -> code=${e.code} status=${e.status} requestId=${e.requestId}');
  }
}
