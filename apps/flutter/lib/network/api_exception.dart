/// A failure reported by the LeenTrack API (or the transport) in a form the UI can act on.
class ApiException implements Exception {
  const ApiException({
    required this.code,
    required this.message,
    this.status,
    this.fields = const {},
    this.requestId,
  });

  /// Machine code from the API envelope (e.g. `validation_failed`), or `network_error` / `bad_response`.
  final String code;
  final String message;
  final int? status;
  final Map<String, List<String>> fields;
  final String? requestId;

  bool get isNetwork => code == 'network_error';
  bool get isUnauthenticated => status == 401;

  @override
  String toString() => 'ApiException($code, $status): $message';
}
