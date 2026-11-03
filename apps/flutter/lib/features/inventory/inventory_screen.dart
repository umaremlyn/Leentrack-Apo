import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';
import '../../core/money.dart';

/// Read-only stock view from the local copy. Quantities already include sales queued on this device.
class InventoryScreen extends ConsumerStatefulWidget {
  const InventoryScreen({super.key});
  @override
  ConsumerState<InventoryScreen> createState() => _InventoryScreenState();
}

class _InventoryScreenState extends ConsumerState<InventoryScreen> {
  String _q = '';
  @override
  Widget build(BuildContext context) {
    final w = ref.watch(sessionProvider).work!;
    ref.watch(syncProvider);
    final locs = w.db.locations();
    final loc = locs.isEmpty ? null : locs.first['id'] as String;
    final rows = w.db.products(search: _q, limit: 1000).where((p) => p['item_type'] == 'product').toList();
    return Column(children: [
      Padding(padding: const EdgeInsets.all(8), child: TextField(decoration: const InputDecoration(prefixIcon: Icon(Icons.search), hintText: 'Search products', border: OutlineInputBorder()), onChanged: (v) => setState(() => _q = v))),
      if (locs.length > 1) Padding(padding: const EdgeInsets.symmetric(horizontal: 12), child: Text('Showing ${locs.first['name']}')),
      Expanded(
        child: loc == null
            ? const Center(child: Text('Nothing downloaded yet'))
            : ListView.builder(
                itemCount: rows.length,
                itemBuilder: (_, i) {
                  final p = rows[i];
                  final q = w.db.available(p['id'] as String, loc);
                  final low = q <= (p['reorder_level'] as int);
                  return ListTile(
                    key: Key('stock-${p['id']}'),
                    title: Text(p['name'] as String),
                    subtitle: Text(p['cost_price'] == null ? Cents.pretty(p['selling_price'] as int) : '${Cents.pretty(p['selling_price'] as int)} · cost ${Cents.pretty(p['cost_price'] as int)}'),
                    trailing: Text('$q', style: TextStyle(fontWeight: FontWeight.bold, color: low ? Theme.of(context).colorScheme.error : null)),
                  );
                },
              ),
      ),
    ]);
  }
}
