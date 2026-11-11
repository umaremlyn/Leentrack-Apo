import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:uuid/uuid.dart';

import '../../app/providers.dart';
import '../../auth/authenticated_client.dart';
import '../common/online.dart';

/// Outlets and moving stock between them. Online only. Needs a plan with multiple outlets: otherwise the server's own message is shown.
class LocationsScreen extends StatelessWidget {
  const LocationsScreen({super.key});
  @override
  Widget build(BuildContext context) {
    return const DefaultTabController(
      length: 2,
      child: Column(children: [
        TabBar(tabs: [Tab(text: 'Outlets'), Tab(text: 'Transfers')]),
        Expanded(child: TabBarView(children: [_OutletsTab(), _TransfersTab()])),
      ]),
    );
  }
}

class _OutletsTab extends ConsumerWidget {
  const _OutletsTab();
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final w = ref.watch(sessionProvider).work!;
    return OnlineData<List<Map<String, dynamic>>>(
      load: () async => ((await w.client.get('/locations')).data as List).cast<Map<String, dynamic>>(),
      builder: (context, rows, reload) => Scaffold(
        floatingActionButton: FloatingActionButton.extended(key: const Key('new-outlet'), onPressed: () => _add(context, ref, reload), icon: const Icon(Icons.add_business_outlined), label: const Text('New outlet')),
        body: ListView(children: [
          for (final l in rows)
            ListTile(
              key: Key('outlet-${l['id']}'),
              leading: const Icon(Icons.place_outlined),
              title: Text('${l['name']}'),
              subtitle: Text([if (l['is_default'] == true) 'Main outlet', if (l['is_active'] != true) 'Closed', l['city'], l['phone']].whereType<String>().join(' · ')),
            ),
        ]),
      ),
    );
  }

  Future<void> _add(BuildContext context, WidgetRef ref, Future<void> Function() reload) async {
    final name = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('New outlet'),
        content: TextField(key: const Key('outlet-name'), controller: name, decoration: const InputDecoration(labelText: 'Name, e.g. Lekki branch')),
        actions: [TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')), FilledButton(key: const Key('outlet-save'), onPressed: () => Navigator.pop(context, true), child: const Text('Save'))],
      ),
    );
    if (ok != true || !context.mounted) return;
    try {
      await ref.read(sessionProvider).work!.client.post('/locations', body: {'name': name.text.trim()});
      ref.read(syncProvider.notifier).trigger(); // the till learns about the new outlet
      await reload();
    } catch (e) {
      if (context.mounted) snack(context, explain(e));
    }
  }
}

String _status(Object? s) => switch ('$s') { 'pending' => 'Not sent yet', 'in_transit' => 'On the way', 'completed' => 'Received', 'cancelled' => 'Cancelled', final x => x };

class _TransfersTab extends ConsumerWidget {
  const _TransfersTab();
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final w = ref.watch(sessionProvider).work!;
    return OnlineData<List<Map<String, dynamic>>>(
      load: () async => ((await w.client.get('/transfers', query: {'per_page': 100})).data as List).cast<Map<String, dynamic>>(),
      builder: (context, rows, reload) => Scaffold(
        floatingActionButton: FloatingActionButton.extended(key: const Key('new-transfer'), onPressed: () => _create(context, ref, reload), icon: const Icon(Icons.swap_horiz), label: const Text('New transfer')),
        body: rows.isEmpty
            ? const Center(child: Text('No transfers yet'))
            : ListView(children: [
                for (final t in rows)
                  ListTile(
                    key: Key('transfer-${t['id']}'),
                    title: Text('${t['transfer_number']} · ${t['from_name']} → ${t['to_name']}'),
                    subtitle: Text('${_status(t['status'])} · ${t['item_count']} item(s)'),
                    onTap: () async {
                      await Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => TransferPage(transferId: t['id'] as String)));
                      await reload();
                    },
                  ),
              ]),
      ),
    );
  }

  Future<void> _create(BuildContext context, WidgetRef ref, Future<void> Function() reload) async {
    final w = ref.read(sessionProvider).work!;
    final locs = w.db.locations();
    if (locs.length < 2) {
      snack(context, 'You need at least two outlets to move stock between them.');
      return;
    }
    final products = w.db.products(limit: 1000).where((p) => p['item_type'] == 'product').toList();
    String from = locs.first['id'] as String;
    String to = locs[1]['id'] as String;
    final lines = <({String id, String name, TextEditingController qty})>[];
    String? error;
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => StatefulBuilder(
        builder: (context, set) => AlertDialog(
          title: const Text('Move stock'),
          content: SizedBox(
            width: 420,
            child: SingleChildScrollView(
              child: Column(mainAxisSize: MainAxisSize.min, children: [
                DropdownButtonFormField<String>(key: const Key('tr-from'), initialValue: from, decoration: const InputDecoration(labelText: 'From'), items: [for (final l in locs) DropdownMenuItem(value: l['id'] as String, child: Text('${l['name']}'))], onChanged: (v) => set(() => from = v ?? from)),
                DropdownButtonFormField<String>(key: const Key('tr-to'), initialValue: to, decoration: const InputDecoration(labelText: 'To'), items: [for (final l in locs) DropdownMenuItem(value: l['id'] as String, child: Text('${l['name']}'))], onChanged: (v) => set(() => to = v ?? to)),
                for (final l in lines)
                  Row(children: [
                    Expanded(child: Text('${l.name} (${w.db.available(l.id, from)} at source)')),
                    SizedBox(width: 70, child: TextField(key: Key('tr-qty-${l.id}'), controller: l.qty, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'Qty'))),
                  ]),
                DropdownButton<String>(
                  key: const Key('tr-add-product'),
                  hint: const Text('Add a product'),
                  value: null,
                  items: [for (final p in products) DropdownMenuItem(value: p['id'] as String, child: Text('${p['name']}'))],
                  onChanged: (id) {
                    if (id == null || lines.any((l) => l.id == id)) return;
                    set(() => lines.add((id: id, name: '${products.firstWhere((p) => p['id'] == id)['name']}', qty: TextEditingController(text: '1'))));
                  },
                ),
                if (error != null) Text(error!, key: const Key('tr-error'), style: TextStyle(color: Theme.of(context).colorScheme.error)),
              ]),
            ),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')),
            FilledButton(
              key: const Key('tr-save'),
              onPressed: () {
                if (from == to) return set(() => error = 'Choose two different outlets.');
                if (lines.isEmpty || lines.any((l) => (int.tryParse(l.qty.text.trim()) ?? 0) < 1)) return set(() => error = 'Add at least one product with a whole quantity.');
                Navigator.pop(context, true);
              },
              child: const Text('Save'),
            ),
          ],
        ),
      ),
    );
    if (ok != true || !context.mounted) return;
    try {
      await w.client.post('/transfers', body: {
        'from_location_id': from,
        'to_location_id': to,
        'items': [for (final l in lines) {'product_id': l.id, 'quantity': int.parse(l.qty.text.trim())}],
      });
      await reload();
    } catch (e) {
      if (context.mounted) snack(context, explain(e));
    }
  }
}

class TransferPage extends ConsumerStatefulWidget {
  const TransferPage({super.key, required this.transferId});
  final String transferId;
  @override
  ConsumerState<TransferPage> createState() => _TransferPageState();
}

class _TransferPageState extends ConsumerState<TransferPage> {
  int _rev = 0;
  // One id per step, kept for the life of this page: pressing twice or retrying after a lost answer cannot move the stock twice.
  final _dispatchOp = const Uuid().v4();
  final _receiveOp = const Uuid().v4();
  AuthenticatedClient get _c => ref.read(sessionProvider).work!.client;

  Future<void> _run(Future<Object?> Function() action, String done) async {
    try {
      await action();
      if (mounted) snack(context, done);
      ref.read(syncProvider.notifier).trigger(); // stock at the outlets changed
    } catch (e) {
      if (mounted) snack(context, explain(e));
    }
    if (mounted) setState(() => _rev++);
  }

  Future<void> _receive(List<Map<String, dynamic>> items) async {
    final ctl = {for (final i in items) i['id'] as String: TextEditingController(text: '${i['quantity_shipped'] ?? i['quantity_requested']}')};
    String? error;
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => StatefulBuilder(
        builder: (context, set) => AlertDialog(
          title: const Text('What arrived?'),
          content: SingleChildScrollView(
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              const Text('Count what actually arrived. Anything missing is recorded as a difference, not hidden.'),
              for (final i in items)
                Row(children: [
                  Expanded(child: Text('${i['product_name']} (sent ${i['quantity_shipped']})')),
                  SizedBox(width: 70, child: TextField(key: Key('tr-recv-${i['id']}'), controller: ctl[i['id']], keyboardType: TextInputType.number)),
                ]),
              if (error != null) Text(error!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')),
            FilledButton(
              key: const Key('tr-recv-confirm'),
              onPressed: () {
                for (final i in items) {
                  final q = int.tryParse(ctl[i['id']]!.text.trim());
                  if (q == null || q < 0 || q > ((i['quantity_shipped'] ?? i['quantity_requested']) as int)) return set(() => error = 'Each count must be between 0 and what was sent.');
                }
                Navigator.pop(context, true);
              },
              child: const Text('Receive'),
            ),
          ],
        ),
      ),
    );
    if (ok != true) return;
    await _run(
      () => _c.post('/transfers/${widget.transferId}/receive', body: {
        'client_operation_id': _receiveOp,
        'items': [for (final i in items) {'item_id': i['id'], 'quantity': int.parse(ctl[i['id']]!.text.trim())}],
      }),
      'Stock received.',
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Transfer')),
      body: OnlineData<Map<String, dynamic>>(
        key: ValueKey(_rev),
        load: () async => Map<String, dynamic>.from((await _c.get('/transfers/${widget.transferId}')).data as Map),
        builder: (context, t, _) {
          final status = '${t['status']}';
          final items = (t['items'] as List).cast<Map<String, dynamic>>();
          return ListView(padding: const EdgeInsets.all(12), children: [
            Text('${t['transfer_number']} · ${t['from_name']} → ${t['to_name']}', style: Theme.of(context).textTheme.titleLarge),
            Text(_status(status), key: const Key('tr-status')),
            if ((t['variance_units'] ?? 0) != 0) Text('${t['variance_units']} unit(s) did not arrive.', key: const Key('tr-variance'), style: TextStyle(color: Theme.of(context).colorScheme.error)),
            const SizedBox(height: 8),
            for (final i in items)
              ListTile(
                dense: true,
                title: Text('${i['product_name']}'),
                subtitle: Text('Asked ${i['quantity_requested']}${i['quantity_shipped'] != null ? ' · sent ${i['quantity_shipped']}' : ''}${i['quantity_received'] != null ? ' · arrived ${i['quantity_received']}' : ''}'),
              ),
            const SizedBox(height: 12),
            Wrap(spacing: 8, children: [
              if (status == 'pending') FilledButton(key: const Key('tr-dispatch'), onPressed: () => _run(() => _c.post('/transfers/${widget.transferId}/dispatch', body: {'client_operation_id': _dispatchOp}), 'Sent. The stock has left ${t['from_name']}.'), child: const Text('Send stock')),
              if (status == 'in_transit') FilledButton(key: const Key('tr-receive'), onPressed: () => _receive(items), child: const Text('Receive stock')),
              if (status == 'pending') OutlinedButton(key: const Key('tr-cancel'), onPressed: () => _run(() => _c.post('/transfers/${widget.transferId}/cancel'), 'Transfer cancelled.'), child: const Text('Cancel')),
            ]),
          ]);
        },
      ),
    );
  }
}
