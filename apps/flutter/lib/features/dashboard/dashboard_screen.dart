import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';
import '../../core/money.dart';
import '../common/online.dart';

/// The owner's numbers, exactly as the server computes them (never recomputed on the device).
class DashboardScreen extends ConsumerWidget {
  const DashboardScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final client = ref.watch(sessionProvider).work!.client;
    return OnlineData<Map<String, dynamic>>(
      load: () async => Map<String, dynamic>.from((await client.get('/dashboard')).data as Map),
      builder: (context, d, reload) {
        final m = Map<String, dynamic>.from(d['metrics'] as Map? ?? {});
        final p = Map<String, dynamic>.from(d['period'] as Map? ?? {});
        String money(String k) => Cents.pretty(Cents.parse('${m[k] ?? '0'}'));
        final tiles = <(String, String)>[
          ('Sales billed', money('revenue_billed')),
          ('Money collected', money('revenue_collected')),
          ('Expenses', money('expenses')),
          ('Gross profit', money('gross_profit')),
          ('Net profit', money('net_profit')),
          ('Transactions', '${m['transactions'] ?? 0}'),
        ];
        return RefreshIndicator(
          onRefresh: reload,
          child: ListView(padding: const EdgeInsets.all(12), children: [
            Text('${p['from']} → ${p['to']}', style: Theme.of(context).textTheme.labelLarge),
            if ((m['items_missing_cost'] ?? 0) != 0) Padding(padding: const EdgeInsets.only(top: 4), child: Text('${m['items_missing_cost']} sold item(s) have no cost price, so profit is incomplete.', style: TextStyle(color: Theme.of(context).colorScheme.error))),
            const SizedBox(height: 8),
            Wrap(spacing: 12, runSpacing: 12, children: [
              for (final t in tiles)
                SizedBox(
                  width: 220,
                  child: Card(child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(t.$1, style: Theme.of(context).textTheme.labelMedium), const SizedBox(height: 6), Text(t.$2, key: Key('tile-${t.$1}'), style: Theme.of(context).textTheme.titleLarge)]))),
                ),
            ]),
          ]),
        );
      },
    );
  }
}
