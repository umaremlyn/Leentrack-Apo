import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'app/providers.dart';
import 'config/app_config.dart';
import 'features/auth/login_screen.dart';
import 'features/home/home_screen.dart';

void main() {
  runApp(const ProviderScope(child: LeenTrackApp()));
}

class LeenTrackApp extends StatelessWidget {
  const LeenTrackApp({super.key, this.configured});

  /// Overridable for tests; the real app asks the build configuration.
  final bool? configured;

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'LeenTrack',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(colorSchemeSeed: const Color(0xFF2563EB), useMaterial3: true),
      home: _Root(configured: configured ?? AppConfig.isConfigured),
    );
  }
}

class _Root extends ConsumerWidget {
  const _Root({required this.configured});
  final bool configured;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (!configured) {
      return const Scaffold(body: Center(child: Padding(padding: EdgeInsets.all(24), child: Text('API_BASE_URL is not set. Run with --dart-define=API_BASE_URL=https://…/api/v1'))));
    }
    final app = ref.watch(sessionProvider);
    return switch (app.stage) {
      Stage.loading => const Scaffold(body: Center(child: CircularProgressIndicator())),
      Stage.signedOut => const LoginScreen(),
      Stage.chooseBusiness => const BusinessPicker(),
      Stage.ready => const HomeScreen(),
    };
  }
}
