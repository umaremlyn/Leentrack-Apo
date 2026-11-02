/// Build-time configuration. Set with: flutter run --dart-define=API_BASE_URL=https://api.example.com/api/v1
/// There is intentionally NO default pointing at production: an unconfigured build must fail visibly.
class AppConfig {
  const AppConfig._();

  static const String apiBaseUrl = String.fromEnvironment('API_BASE_URL');

  static bool get isConfigured => apiBaseUrl.isNotEmpty;
}
