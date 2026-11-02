import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'app_shell.dart';
import 'config/app_config.dart';
import 'features/settings/health_provider.dart';
import 'network/api_exception.dart';

void main() {
  runApp(const ProviderScope(child: LeenTrackApp()));
}

class LeenTrackApp extends StatelessWidget {
  const LeenTrackApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'LeenTrack',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(colorSchemeSeed: const Color(0xFF2563EB), useMaterial3: true),
      home: const _Home(),
    );
  }
}

/// Phase 1 home: proves shell + networking against the real API. Real screens arrive in Phases 2–5.
class _Home extends StatefulWidget {
  const _Home();
  @override
  State<_Home> createState() => _HomeState();
}

class _HomeState extends State<_Home> {
  String _selected = 'dashboard';

  @override
  Widget build(BuildContext context) {
    return AppShell(
      role: 'owner', // Phase 1 only: the role will come from the authenticated membership in Phase 2.
      selectedId: _selected,
      onSelect: (id) => setState(() => _selected = id),
      body: _selected == 'dashboard' ? const _ServerStatus() : Center(child: Text('$_selected — not built yet')),
    );
  }
}

class _ServerStatus extends ConsumerWidget {
  const _ServerStatus();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (!AppConfig.isConfigured) {
      return const Center(child: Text('API_BASE_URL is not set. Run with --dart-define=API_BASE_URL=https://…/api/v1'));
    }
    final health = ref.watch(serverHealthProvider);
    return Center(
      child: health.when(
        loading: () => const CircularProgressIndicator(),
        error: (e, _) => Column(mainAxisSize: MainAxisSize.min, children: [
          Text(e is ApiException ? e.message : 'Unexpected error'),
          TextButton(onPressed: () => ref.invalidate(serverHealthProvider), child: const Text('Retry')),
        ]),
        data: (h) => Text('Server ${h.status} · db ${h.db} · v${h.version}'),
      ),
    );
  }
}
