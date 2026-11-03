# LeenTrack — Flutter app (single codebase: Android, iOS, Windows, macOS, Linux)

Riverpod (state) · Dio (HTTP) · flutter_secure_storage (refresh token) · SQLite via the `sqlite3` package with versioned migrations (D-98) · offline-first sync per `docs/14_SYNC_PROTOCOL.md`.

## Layout
`lib/config` build-time config · `lib/network` API client + error type · `lib/core` breakpoints & navigation model · `lib/app_shell.dart` adaptive shell (bottom bar / rail / sidebar) · `lib/features/*` one folder per module (filled phase by phase) · `lib/database`, `lib/auth` (Phases 2/4).
Rule: no API or database calls inside widgets — widgets read Riverpod providers; providers call repositories; repositories call `ApiClient`.

## Layers (Phase 5)
`lib/network` ApiClient (envelope, errors) → `lib/auth` TokenStore, AuthenticatedClient (bearer + business header, single-flight refresh), AuthService → `lib/data/local_db.dart` SQLite (catalogue copy, outbox, stock promises) → `lib/sync` SyncApi + SyncEngine (push ≤100 in order, back-off, pull cursors, tombstones, daily full resync) → `lib/features/pos` Cart, CheckoutMath (golden-verified), PosService → `lib/app` providers & WorkSession → screens in `lib/features/*`.

## Run / test
```bash
flutter pub get
flutter analyze
flutter test
flutter run --dart-define=API_BASE_URL=https://api.example.com/api/v1     # no default: an unconfigured build says so
dart run tool/live_check.dart http://127.0.0.1:8080/api/v1                # manual smoke check against a running backend
# end-to-end: the real Dart code against the real PHP API + MySQL (throw-away database leentrack_e2e*; never production)
TEST_DB_USER=… TEST_DB_PASS=… TEST_DB_SOCKET=… ../../tools/e2e/run.sh
```
## Build status (honest)
| Platform | Scaffolded | Built here | Notes |
|---|---|---|---|
| Android / iOS / Windows / macOS / Linux | yes (`flutter create`) | **no** | Phase 1 verified `analyze` + unit/widget tests only. Android needs the Android SDK; iOS/macOS need macOS + Xcode (cannot be built in this Linux sandbox); Windows needs Visual Studio; Linux needs clang/cmake/ninja/GTK. Build procedures are written in Phase 9 and each platform is marked verified only when actually built. |
