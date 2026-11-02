# LeenTrack — Flutter app (single codebase: Android, iOS, Windows, macOS, Linux)

Riverpod (state) · Dio (HTTP) · flutter_secure_storage (tokens, wired in Phase 2) · SQLite via drift (Phase 4, after plugin check on all five platforms).

## Layout
`lib/config` build-time config · `lib/network` API client + error type · `lib/core` breakpoints & navigation model · `lib/app_shell.dart` adaptive shell (bottom bar / rail / sidebar) · `lib/features/*` one folder per module (filled phase by phase) · `lib/database`, `lib/auth` (Phases 2/4).
Rule: no API or database calls inside widgets — widgets read Riverpod providers; providers call repositories; repositories call `ApiClient`.

## Run / test
```bash
flutter pub get
flutter analyze
flutter test
flutter run --dart-define=API_BASE_URL=https://api.example.com/api/v1     # no default: an unconfigured build says so
dart run tool/live_check.dart http://127.0.0.1:8080/api/v1                # manual smoke check against a running backend
```
## Build status (honest)
| Platform | Scaffolded | Built here | Notes |
|---|---|---|---|
| Android / iOS / Windows / macOS / Linux | yes (`flutter create`) | **no** | Phase 1 verified `analyze` + unit/widget tests only. Android needs the Android SDK; iOS/macOS need macOS + Xcode (cannot be built in this Linux sandbox); Windows needs Visual Studio; Linux needs clang/cmake/ninja/GTK. Build procedures are written in Phase 9 and each platform is marked verified only when actually built. |
