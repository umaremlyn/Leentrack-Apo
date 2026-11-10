# 15 — Building and releasing the Flutter app

**Status: procedures only. No platform binary has been built by this project yet** (the sandbox has no Android SDK or desktop toolchains, and iOS/macOS need a Mac). Each platform is marked *verified* here only after someone builds it and runs the checklist at the bottom. Today verified: `flutter analyze`, `flutter test` (unit, widget, database, sync), and the end-to-end suite against the real PHP API.

## Automatic builds (GitHub Actions) — written, NOT yet run
`.github/workflows/backend.yml` runs the PHP tests on PHP 8.3 and 8.4 against MySQL 8.4. `.github/workflows/flutter.yml` runs analyze + tests, the end-to-end suite, then builds Android (APK), Linux, Windows, macOS and iOS (unsigned) and uploads each as a downloadable artifact (kept 14 days).
**I could not run these here** (no GitHub runners in this sandbox), so treat the first run as the real test: expect to fix small things (a missing system library, an action version). Only the commands inside them were exercised locally (analyze, tests, the end-to-end script in both socket and TCP mode).
To use them: push, open the repository's *Actions* tab, and (for builds) first add the repository variable `STAGING_API_URL` (Settings > Secrets and variables > Actions > Variables) — a STAGING address, never production; the build job refuses to run without it. Builds are unsigned: Android uses the debug key, macOS/iOS are not code-signed, so they are for testing on your own devices. Store releases need signing keys and an Apple developer account, kept as encrypted repository secrets, not in the code.

## Common
```bash
cd apps/flutter
flutter pub get
flutter analyze && flutter test
# the API address is a build-time define; there is deliberately no default (an unconfigured build says so)
--dart-define=API_BASE_URL=https://api.<your-domain>/api/v1
```
End-to-end against a throw-away local server: `tools/e2e/run.sh` (needs MySQL, PHP, Flutter; refuses any database not named `leentrack_e2e*`).

## Per platform (what each needs)
| Platform | Needs | Command | Notes |
|---|---|---|---|
| Android | Android SDK, JDK 17 | `flutter build apk --release --dart-define=…` (or `appbundle`) | Signing key stays outside the repo. `flutter_secure_storage` uses the Android Keystore. |
| iOS | macOS + Xcode, Apple developer account | `flutter build ipa --dart-define=…` | Keychain used for tokens. |
| macOS | macOS + Xcode | `flutter build macos --dart-define=…` | Needs the keychain and network-client entitlements in `macos/Runner/*.entitlements`. |
| Windows | Windows 10/11, Visual Studio 2022 "Desktop development with C++" | `flutter build windows --dart-define=…` | Installer (MSIX/Inno) is not set up. |
| Linux | clang, cmake, ninja, GTK 3 dev, **libsecret** dev (token storage), libsqlite3 | `flutter build linux --dart-define=…` | |

## Where data lives on a device
* Tokens (refresh token, chosen business, device id): the platform keychain/keystore.
* Work data: `leentrack_<userId>_<businessId>.db` in the application-support folder. Signing out keeps it (queued sales survive). It is **not encrypted** (G-89).
* Saved receipts: the documents folder.

## Verification checklist per platform (tick only when done on that platform)
1. App starts, shows the sign-in screen; wrong password shows the server message.
2. Sign in, first sync fills products and stock.
3. Airplane mode: make a sale, create a customer; the bar shows them waiting.
4. Reconnect: both sync; the receipt number exists on the server; stock matches.
5. Quit and reopen while offline with a queued sale: it is still queued.
6. Sign out and in again: the queue is still there.
7. Desktop: F2/F9/F4, a USB barcode scanner adds items; the PDF receipt saves and opens.

## Release hygiene
Never ship a build pointed at production until the owner has authorised Phase 10. Builds for rehearsal use a staging API URL and a staging database only.
