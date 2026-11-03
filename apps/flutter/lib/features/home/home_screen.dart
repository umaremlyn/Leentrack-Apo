import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';
import '../../app_shell.dart';
import '../customers/customers_screen.dart';
import '../inventory/inventory_screen.dart';
import '../pos/pos_screen.dart';
import '../sales/sales_screen.dart';
import '../settings/health_provider.dart';
import '../sync/sync_screen.dart';

/// The signed-in app: real role from the membership, the sync bar on top, the offline-capable screens wired in.
/// Screens that need the server's full picture (reports, purchases, …) are Phase 6/7 and say so instead of pretending.
class HomeScreen extends ConsumerStatefulWidget {
  const HomeScreen({super.key});
  @override
  ConsumerState<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends ConsumerState<HomeScreen> {
  String? _selected;

  @override
  Widget build(BuildContext context) {
    final app = ref.watch(sessionProvider);
    final w = app.work!;
    final selected = _selected ?? 'pos';
    return AppShell(
      role: w.role,
      selectedId: selected,
      onSelect: (id) => setState(() => _selected = id),
      body: Column(children: [
        Material(
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
            child: Row(children: [
              Expanded(child: Text('${w.membership.name} · ${w.role}', style: Theme.of(context).textTheme.titleSmall, overflow: TextOverflow.ellipsis)),
              if ((app.user?.memberships.length ?? 0) > 1) IconButton(tooltip: 'Switch business', icon: const Icon(Icons.swap_horiz), onPressed: () => ref.read(sessionProvider.notifier).switchBusiness()),
              IconButton(key: const Key('logout'), tooltip: 'Sign out', icon: const Icon(Icons.logout), onPressed: () => ref.read(sessionProvider.notifier).logout()),
            ]),
          ),
        ),
        SyncBar(onOpen: () => Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => const SyncScreen()))),
        Expanded(child: _body(selected)),
      ]),
    );
  }

  Widget _body(String id) => switch (id) {
        'pos' => const PosScreen(),
        'customers' => const CustomersScreen(),
        'inventory' => const InventoryScreen(),
        'sales' => const SalesScreen(),
        'dashboard' => const _ServerStatus(),
        _ => Center(child: Padding(padding: const EdgeInsets.all(24), child: Text('“$id” needs the server and is part of a later phase.', textAlign: TextAlign.center))),
      };
}

class _ServerStatus extends ConsumerWidget {
  const _ServerStatus();
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final health = ref.watch(serverHealthProvider);
    return Center(child: health.when(loading: () => const CircularProgressIndicator(), error: (_, _) => const Text('Server not reachable'), data: (h) => Text('Server ${h.status} · v${h.version}')));
  }
}
