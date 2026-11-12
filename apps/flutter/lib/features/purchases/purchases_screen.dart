import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:uuid/uuid.dart';

import '../../app/providers.dart';
import '../../auth/authenticated_client.dart';
import '../../core/money.dart';
import '../common/online.dart';

String _m(Object? v) => Cents.pretty(Cents.parse('${v ?? '0'}'));

/// Stock coming in: purchase orders (create, place, receive, pay, cancel/close) and suppliers. Online only: stock and what you owe are the server's.
class PurchasesScreen extends StatelessWidget {
  const PurchasesScreen({super.key});
  @override
  Widget build(BuildContext context) {
    return const DefaultTabController(
      length: 2,
      child: Column(children: [
        TabBar(tabs: [Tab(text: 'Orders'), Tab(text: 'Suppliers')]),
        Expanded(child: TabBarView(children: [_OrdersTab(), _SuppliersTab()])),
      ]),
    );
  }
}

class _OrdersTab extends ConsumerWidget {
  const _OrdersTab();
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final w = ref.watch(sessionProvider).work!;
    return OnlineData<List<Map<String, dynamic>>>(
      load: () async => ((await w.client.get('/purchase-orders', query: {'per_page': 100})).data as List).cast<Map<String, dynamic>>(),
      builder: (context, rows, reload) => Scaffold(
        floatingActionButton: FloatingActionButton.extended(key: const Key('new-po'), onPressed: () => _create(context, ref, reload), icon: const Icon(Icons.add), label: const Text('New order')),
        body: rows.isEmpty
            ? const Center(child: Text('No purchase orders yet'))
            : RefreshIndicator(
                onRefresh: reload,
                child: ListView(children: [
                  for (final o in rows)
                    ListTile(
                      key: Key('po-${o['id']}'),
                      title: Text('${o['order_number']} · ${o['supplier_name'] ?? 'No supplier'}'),
                      subtitle: Text('${_label(o['status'])} · ${o['item_count']} item(s)'),
                      trailing: Text(_m(o['total_cost'])),
                      onTap: () async {
                        await Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => PurchaseOrderPage(orderId: o['id'] as String)));
                        await reload();
                      },
                    ),
                ]),
              ),
      ),
    );
  }

  Future<void> _create(BuildContext context, WidgetRef ref, Future<void> Function() reload) async {
    final w = ref.read(sessionProvider).work!;
    List<Map<String, dynamic>> suppliers = const [];
    try {
      suppliers = ((await w.client.get('/suppliers', query: {'per_page': 100})).data as List).cast<Map<String, dynamic>>();
    } catch (e) {
      if (context.mounted) snack(context, explain(e));
      return;
    }
    if (!context.mounted) return;
    final products = w.db.products(limit: 1000).where((p) => p['item_type'] == 'product').toList();
    String? supplier;
    final lines = <({String id, String name, TextEditingController qty, TextEditingController cost})>[];
    String? error;
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => StatefulBuilder(
        builder: (context, set) => AlertDialog(
          title: const Text('New purchase order'),
          content: SizedBox(
            width: 460,
            child: SingleChildScrollView(
              child: Column(mainAxisSize: MainAxisSize.min, children: [
                DropdownButtonFormField<String?>(
                  key: const Key('po-supplier'),
                  initialValue: supplier,
                  decoration: const InputDecoration(labelText: 'Supplier'),
                  items: [const DropdownMenuItem(value: null, child: Text('— none —')), for (final s in suppliers) DropdownMenuItem(value: s['id'] as String, child: Text('${s['name']}'))],
                  onChanged: (v) => set(() => supplier = v),
                ),
                for (final l in lines)
                  Row(children: [
                    Expanded(flex: 3, child: Text(l.name)),
                    SizedBox(width: 70, child: TextField(controller: l.qty, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'Qty'))),
                    const SizedBox(width: 8),
                    SizedBox(width: 90, child: TextField(controller: l.cost, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: const InputDecoration(labelText: 'Unit cost'))),
                  ]),
                const SizedBox(height: 8),
                DropdownButton<String>(
                  key: const Key('po-add-product'),
                  hint: const Text('Add a product'),
                  value: null,
                  items: [for (final p in products) DropdownMenuItem(value: p['id'] as String, child: Text('${p['name']}'))],
                  onChanged: (id) {
                    if (id == null || lines.any((l) => l.id == id)) return;
                    final p = products.firstWhere((p) => p['id'] == id);
                    set(() => lines.add((id: id, name: '${p['name']}', qty: TextEditingController(text: '1'), cost: TextEditingController(text: Cents.format((p['cost_price'] as int?) ?? 0)))));
                  },
                ),
                if (error != null) Text(error!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
              ]),
            ),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')),
            FilledButton(
              key: const Key('po-save'),
              onPressed: () {
                try {
                  if (lines.isEmpty) throw const FormatException();
                  for (final l in lines) {
                    if (int.parse(l.qty.text.trim()) < 1) throw const FormatException();
                    if (Cents.parse(l.cost.text) < 0) throw const FormatException();
                  }
                  Navigator.pop(context, true);
                } on FormatException {
                  set(() => error = 'Add at least one product, with a whole quantity and a cost.');
                }
              },
              child: const Text('Save draft'),
            ),
          ],
        ),
      ),
    );
    if (ok != true || !context.mounted) return;
    try {
      await w.client.post('/purchase-orders', body: {
        'supplier_id': ?supplier,
        'items': [for (final l in lines) {'product_id': l.id, 'quantity': int.parse(l.qty.text.trim()), 'unit_cost': Cents.format(Cents.parse(l.cost.text))}],
      });
      await reload();
    } catch (e) {
      if (context.mounted) snack(context, explain(e));
    }
  }
}

String _label(Object? status) => switch ('$status') {
      'draft' => 'Draft',
      'ordered' => 'Ordered',
      'partially_received' => 'Partly received',
      'received' => 'Received',
      'cancelled' => 'Cancelled',
      'closed' => 'Closed',
      final s => s,
    };

class PurchaseOrderPage extends ConsumerStatefulWidget {
  const PurchaseOrderPage({super.key, required this.orderId});
  final String orderId;
  @override
  ConsumerState<PurchaseOrderPage> createState() => _PurchaseOrderPageState();
}

class _PurchaseOrderPageState extends ConsumerState<PurchaseOrderPage> {
  int _rev = 0;
  AuthenticatedClient get _c => ref.read(sessionProvider).work!.client;

  Future<void> _run(Future<Object?> Function() action, String done) async {
    try {
      await action();
      if (mounted) snack(context, done);
    } catch (e) {
      if (mounted) snack(context, explain(e));
    }
    if (mounted) setState(() => _rev++);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Purchase order')),
      body: OnlineData<Map<String, dynamic>>(
        key: ValueKey(_rev),
        load: () async => Map<String, dynamic>.from((await _c.get('/purchase-orders/${widget.orderId}')).data as Map),
        builder: (context, o, reload) {
          final status = '${o['status']}';
          final items = (o['items'] as List).cast<Map<String, dynamic>>();
          final balance = Cents.parse('${o['balance_due'] ?? '0'}');
          return ListView(padding: const EdgeInsets.all(12), children: [
            Text('${o['order_number']} · ${o['supplier_name'] ?? 'No supplier'}', style: Theme.of(context).textTheme.titleLarge),
            Text('${_label(status)} · into ${o['outlet_name']}', key: const Key('po-status')),
            const SizedBox(height: 8),
            for (final i in items)
              ListTile(
                dense: true,
                title: Text('${i['product_name']}'),
                subtitle: Text('Ordered ${i['quantity_ordered']} · received ${i['quantity_received']} · outstanding ${i['outstanding']}'),
                trailing: Text(_m(i['line_total'])),
              ),
            const Divider(),
            _row('Order total', _m(o['total_cost'])),
            _row('Received so far (value)', _m(o['received_value'])),
            _row('Paid', _m(o['paid'])),
            _row('You owe the supplier', _m(o['balance_due']), bold: true),
            const SizedBox(height: 12),
            Wrap(spacing: 8, runSpacing: 8, children: [
              if (status == 'draft') FilledButton(key: const Key('po-place'), onPressed: () => _run(() => _c.post('/purchase-orders/${widget.orderId}/order'), 'Order placed.'), child: const Text('Place order')),
              if (status == 'ordered' || status == 'partially_received') FilledButton(key: const Key('po-receive'), onPressed: () => _receive(items), child: const Text('Receive stock')),
              if (balance > 0) OutlinedButton(key: const Key('po-pay'), onPressed: () => _pay(balance), child: const Text('Record payment')),
              if (status == 'ordered') OutlinedButton(onPressed: () => _confirm('Cancel this order?', () => _run(() => _c.post('/purchase-orders/${widget.orderId}/cancel'), 'Order cancelled.')), child: const Text('Cancel order')),
              if (status == 'partially_received') OutlinedButton(key: const Key('po-close'), onPressed: () => _confirm('Close this order? What has not arrived will no longer be expected.', () => _run(() => _c.post('/purchase-orders/${widget.orderId}/close'), 'Order closed.')), child: const Text('Close order')),
            ]),
          ]);
        },
      ),
    );
  }

  Widget _row(String a, String b, {bool bold = false}) {
    final s = bold ? const TextStyle(fontWeight: FontWeight.bold) : null;
    return Padding(padding: const EdgeInsets.symmetric(vertical: 2), child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [Flexible(child: Text(a, style: s)), Text(b, style: s)]));
  }

  Future<void> _confirm(String question, Future<void> Function() then) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(title: Text(question), actions: [TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('No')), FilledButton(key: const Key('confirm-yes'), onPressed: () => Navigator.pop(context, true), child: const Text('Yes'))]),
    );
    if (ok == true) await then();
  }

  Future<void> _receive(List<Map<String, dynamic>> items) async {
    final open = items.where((i) => (i['outstanding'] as int) > 0).toList();
    final ctl = {for (final i in open) i['id'] as String: TextEditingController(text: '${i['outstanding']}')};
    final opId = const Uuid().v4(); // one id per dialog: a double press or a retry after a lost answer cannot add the stock twice
    String? error;
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => StatefulBuilder(
        builder: (context, set) => AlertDialog(
          title: const Text('Receive stock'),
          content: SingleChildScrollView(
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              for (final i in open)
                Row(children: [
                  Expanded(child: Text('${i['product_name']} (outstanding ${i['outstanding']})')),
                  SizedBox(width: 70, child: TextField(key: Key('recv-${i['id']}'), controller: ctl[i['id']], keyboardType: TextInputType.number)),
                ]),
              if (error != null) Text(error!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')),
            FilledButton(
              key: const Key('recv-confirm'),
              onPressed: () {
                var any = false;
                for (final i in open) {
                  final q = int.tryParse(ctl[i['id']]!.text.trim());
                  if (q == null || q < 0 || q > (i['outstanding'] as int)) {
                    set(() => error = 'Each quantity must be between 0 and what is outstanding.');
                    return;
                  }
                  if (q > 0) any = true;
                }
                if (!any) {
                  set(() => error = 'Enter at least one quantity.');
                  return;
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
      () => _c.post('/purchase-orders/${widget.orderId}/receive', body: {
        'client_operation_id': opId,
        'items': [for (final i in open) if ((int.tryParse(ctl[i['id']]!.text.trim()) ?? 0) > 0) {'item_id': i['id'], 'quantity': int.parse(ctl[i['id']]!.text.trim())}],
      }),
      'Stock received.',
    );
  }

  Future<void> _pay(int balance) async {
    final amount = TextEditingController(text: Cents.format(balance));
    final opId = const Uuid().v4();
    String? error;
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => StatefulBuilder(
        builder: (context, set) => AlertDialog(
          title: Text('Pay supplier (owing ${Cents.pretty(balance)})'),
          content: TextField(key: const Key('pay-sup-amount'), controller: amount, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: InputDecoration(labelText: 'Amount', errorText: error)),
          actions: [
            TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')),
            FilledButton(
              key: const Key('pay-sup-confirm'),
              onPressed: () {
                try {
                  final a = Cents.parse(amount.text);
                  if (a <= 0 || a > balance) {
                    set(() => error = 'Between 0.01 and ${Cents.format(balance)}');
                    return;
                  }
                  Navigator.pop(context, true);
                } on FormatException {
                  set(() => error = 'Enter an amount like 500 or 500.50');
                }
              },
              child: const Text('Pay'),
            ),
          ],
        ),
      ),
    );
    if (ok != true) return;
    await _run(() => _c.post('/purchase-orders/${widget.orderId}/payments', body: {'amount': Cents.format(Cents.parse(amount.text)), 'method': 'cash', 'client_operation_id': opId}), 'Payment recorded.');
  }
}

class _SuppliersTab extends ConsumerWidget {
  const _SuppliersTab();
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final w = ref.watch(sessionProvider).work!;
    return OnlineData<List<Map<String, dynamic>>>(
      load: () async => ((await w.client.get('/suppliers', query: {'per_page': 100})).data as List).cast<Map<String, dynamic>>(),
      builder: (context, rows, reload) => Scaffold(
        floatingActionButton: FloatingActionButton.extended(key: const Key('new-supplier'), onPressed: () => _add(context, w.client, reload), icon: const Icon(Icons.add), label: const Text('New supplier')),
        body: rows.isEmpty
            ? const Center(child: Text('No suppliers yet'))
            : ListView(children: [
                for (final s in rows)
                  ListTile(
                    key: Key('supplier-${s['id']}'),
                    title: Text('${s['name']}'),
                    subtitle: Text([s['phone'], '${s['open_orders']} open order(s)'].whereType<String>().join(' · ')),
                    trailing: Text('Owed ${_m(s['balance_owed'])}'),
                  ),
              ]),
      ),
    );
  }

  Future<void> _add(BuildContext context, AuthenticatedClient client, Future<void> Function() reload) async {
    final name = TextEditingController();
    final phone = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('New supplier'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(key: const Key('supplier-name'), controller: name, decoration: const InputDecoration(labelText: 'Name')),
          TextField(controller: phone, keyboardType: TextInputType.phone, decoration: const InputDecoration(labelText: 'Phone (optional)')),
        ]),
        actions: [TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')), FilledButton(key: const Key('supplier-save'), onPressed: () => Navigator.pop(context, true), child: const Text('Save'))],
      ),
    );
    if (ok != true || !context.mounted) return;
    try {
      await client.post('/suppliers', body: {'name': name.text.trim(), if (phone.text.trim().isNotEmpty) 'phone': phone.text.trim()});
      await reload();
    } catch (e) {
      if (context.mounted) snack(context, explain(e));
    }
  }
}
