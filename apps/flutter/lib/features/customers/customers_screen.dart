import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';
import '../../core/money.dart';
import '../pos/pos_service.dart';

class CustomersScreen extends ConsumerStatefulWidget {
  const CustomersScreen({super.key});
  @override
  ConsumerState<CustomersScreen> createState() => _CustomersScreenState();
}

class _CustomersScreenState extends ConsumerState<CustomersScreen> {
  String _q = '';

  @override
  Widget build(BuildContext context) {
    final w = ref.watch(sessionProvider).work!;
    ref.watch(syncProvider);
    final rows = w.db.customers(search: _q);
    return Scaffold(
      floatingActionButton: FloatingActionButton.extended(key: const Key('add-customer'), onPressed: _add, icon: const Icon(Icons.person_add_alt), label: const Text('New customer')),
      body: Column(children: [
        Padding(padding: const EdgeInsets.all(8), child: TextField(decoration: const InputDecoration(prefixIcon: Icon(Icons.search), hintText: 'Search name or phone', border: OutlineInputBorder()), onChanged: (v) => setState(() => _q = v))),
        Expanded(
          child: rows.isEmpty
              ? const Center(child: Text('No customers'))
              : ListView.builder(
                  itemCount: rows.length,
                  itemBuilder: (_, i) {
                    final c = rows[i];
                    return ListTile(
                      key: Key('customer-${c['id']}'),
                      leading: const CircleAvatar(child: Icon(Icons.person_outline)),
                      title: Text(c['name'] as String),
                      subtitle: Text([c['phone'], c['email']].whereType<String>().join(' · ')),
                      trailing: c['local_only'] == 1 ? const Chip(label: Text('Not synced yet')) : Text(Cents.pretty(c['total_spent'] as int)),
                    );
                  },
                ),
        ),
      ]),
    );
  }

  Future<void> _add() async {
    final name = TextEditingController();
    final phone = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('New customer'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(key: const Key('customer-name'), controller: name, decoration: const InputDecoration(labelText: 'Name')),
          TextField(controller: phone, keyboardType: TextInputType.phone, decoration: const InputDecoration(labelText: 'Phone (optional)')),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')),
          FilledButton(key: const Key('customer-save'), onPressed: () => Navigator.pop(context, true), child: const Text('Save')),
        ],
      ),
    );
    if (ok != true || !mounted) return;
    try {
      ref.read(sessionProvider).work!.pos.createCustomer(name: name.text, phone: phone.text);
      ref.read(syncProvider.notifier).refresh();
      setState(() {});
      ref.read(syncProvider.notifier).trigger();
    } on CheckoutError catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
  }
}
