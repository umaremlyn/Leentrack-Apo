import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';
import '../../core/money.dart';
import 'cart_provider.dart';
import 'pos_service.dart';

class PosScreen extends ConsumerStatefulWidget {
  const PosScreen({super.key});
  @override
  ConsumerState<PosScreen> createState() => _PosScreenState();
}

class _PosScreenState extends ConsumerState<PosScreen> {
  String _q = '';

  String _location() {
    final w = ref.read(sessionProvider).work!;
    final locs = w.db.locations();
    return locs.isEmpty ? '' : locs.first['id'] as String;
  }

  void _toast(String m) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(m)));

  @override
  Widget build(BuildContext context) {
    final w = ref.watch(sessionProvider).work!;
    ref.watch(syncProvider); // stock numbers change after a sync
    final cartView = ref.watch(cartProvider);
    final loc = _location();
    if (loc.isEmpty) {
      return const Center(child: Padding(padding: EdgeInsets.all(24), child: Text('No products downloaded yet. Connect to the internet once so the till can load your catalogue.', textAlign: TextAlign.center)));
    }
    final products = w.db.products(search: _q);
    final list = Column(children: [
      Padding(
        padding: const EdgeInsets.all(8),
        child: TextField(key: const Key('pos-search'), decoration: const InputDecoration(prefixIcon: Icon(Icons.search), hintText: 'Search name, SKU or barcode', border: OutlineInputBorder()), onChanged: (v) => setState(() => _q = v)),
      ),
      Expanded(
        child: ListView.builder(
          itemCount: products.length,
          itemBuilder: (_, i) {
            final p = products[i];
            final tracks = p['item_type'] == 'product';
            final avail = w.db.available(p['id'] as String, loc);
            final out = tracks && avail <= 0;
            return ListTile(
              key: Key('product-${p['id']}'),
              enabled: !out,
              title: Text(p['name'] as String),
              subtitle: Text(tracks ? (out ? 'Out of stock' : '$avail in stock') : 'Service'),
              trailing: Text(Cents.pretty(p['selling_price'] as int)),
              onTap: () {
                final err = ref.read(cartProvider.notifier).addProduct(p, loc);
                if (err != null) _toast(err);
              },
            );
          },
        ),
      ),
    ]);
    final cart = cartView.cart;
    return LayoutBuilder(builder: (context, c) {
      if (c.maxWidth >= 900) {
        return Row(children: [Expanded(child: list), const VerticalDivider(width: 1), SizedBox(width: 380, child: CartPanel(locationId: loc))]);
      }
      return Column(children: [
        Expanded(child: list),
        if (!cart.isEmpty)
          Material(
            elevation: 6,
            child: ListTile(
              key: const Key('cart-bar'),
              leading: const Icon(Icons.shopping_cart_outlined),
              title: Text('${cart.lines.fold<int>(0, (a, l) => a + l.quantity)} item(s)'),
              trailing: Text(Cents.pretty(cart.total), style: Theme.of(context).textTheme.titleMedium),
              onTap: () => showModalBottomSheet<void>(context: context, isScrollControlled: true, builder: (_) => SizedBox(height: MediaQuery.of(context).size.height * 0.85, child: CartPanel(locationId: loc))),
            ),
          ),
      ]);
    });
  }
}

class CartPanel extends ConsumerWidget {
  const CartPanel({super.key, required this.locationId});
  final String locationId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final w = ref.watch(sessionProvider).work!;
    final view = ref.watch(cartProvider);
    final cart = view.cart;
    final ctl = ref.read(cartProvider.notifier);
    final d = cart.discounts;
    final t = cart.totals;
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      ListTile(
        key: const Key('cart-customer'),
        leading: const Icon(Icons.person_outline),
        title: Text(cart.customerId == null ? 'Walk-in customer' : (w.db.customers().where((c) => c['id'] == cart.customerId).firstOrNull?['name'] as String? ?? 'Customer')),
        trailing: cart.customerId == null ? const Icon(Icons.chevron_right) : IconButton(icon: const Icon(Icons.close), onPressed: () => ctl.setCustomer(null)),
        onTap: () async {
          final c = await showDialog<Map<String, Object?>>(context: context, builder: (_) => _CustomerPicker(w.db.customers()));
          if (c != null) ctl.setCustomer(c);
        },
      ),
      const Divider(height: 1),
      Expanded(
        child: cart.isEmpty
            ? const Center(child: Text('Cart is empty'))
            : ListView(children: [
                for (final l in cart.lines)
                  ListTile(
                    key: Key('line-${l.productId}'),
                    title: Text(l.name),
                    subtitle: Text('${Cents.pretty(l.unitPrice)} × ${l.quantity}'),
                    trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                      IconButton(icon: const Icon(Icons.remove_circle_outline), onPressed: () => ctl.setQuantity(l.productId, l.quantity - 1, locationId)),
                      Text('${l.quantity}'),
                      IconButton(
                        icon: const Icon(Icons.add_circle_outline),
                        onPressed: () {
                          final e = ctl.setQuantity(l.productId, l.quantity + 1, locationId);
                          if (e != null) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e)));
                        },
                      ),
                    ]),
                  ),
              ]),
      ),
      const Divider(height: 1),
      Padding(
        padding: const EdgeInsets.all(12),
        child: Column(children: [
          _row('Subtotal', Cents.pretty(cart.subtotal)),
          if (d.groupDiscount > 0) _row('Group discount', '− ${Cents.pretty(d.groupDiscount)}'),
          if (d.manual > 0) _row('Discount', '− ${Cents.pretty(d.manual)}'),
          if (t.tax > 0) _row('Tax', Cents.pretty(t.tax)),
          _row('Total (provisional)', Cents.pretty(cart.total), bold: true),
          const SizedBox(height: 8),
          Row(children: [
            Expanded(child: OutlinedButton(onPressed: cart.isEmpty ? null : ctl.clear, child: const Text('Clear'))),
            const SizedBox(width: 8),
            Expanded(
              flex: 2,
              child: FilledButton(
                key: const Key('checkout'),
                onPressed: cart.isEmpty ? null : () => _checkout(context, ref),
                child: const Text('Checkout'),
              ),
            ),
          ]),
        ]),
      ),
    ]);
  }

  Widget _row(String a, String b, {bool bold = false}) {
    final s = bold ? const TextStyle(fontWeight: FontWeight.bold, fontSize: 18) : null;
    return Padding(
        padding: const EdgeInsets.symmetric(vertical: 2),
        child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [Flexible(child: Text(a, style: s, overflow: TextOverflow.ellipsis)), const SizedBox(width: 8), Text(b, style: s)]));
  }

  Future<void> _checkout(BuildContext context, WidgetRef ref) async {
    final w = ref.read(sessionProvider).work!;
    final cart = ref.read(cartProvider).cart;
    final res = await showDialog<_PayChoice>(context: context, builder: (_) => _PayDialog(total: cart.total, hasCustomer: cart.customerId != null));
    if (res == null || !context.mounted) return;
    try {
      final sale = w.pos.checkout(cart, locationId: locationId, credit: res.credit, method: res.method, amount: res.amount);
      ref.read(cartProvider.notifier).clear();
      ref.read(syncProvider.notifier).refresh();
      if (Navigator.of(context).canPop() && MediaQuery.of(context).size.width < 900) Navigator.of(context).pop(); // close the cart sheet on phones
      if (!context.mounted) return;
      await showDialog<void>(context: context, builder: (_) => _ReceiptDialog(sale));
      ref.read(syncProvider.notifier).trigger();
    } on CheckoutError catch (e) {
      if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
  }
}

class _PayChoice {
  const _PayChoice(this.credit, this.method, this.amount);
  final bool credit;
  final String method;
  final int amount;
}

class _PayDialog extends StatefulWidget {
  const _PayDialog({required this.total, required this.hasCustomer});
  final int total;
  final bool hasCustomer;
  @override
  State<_PayDialog> createState() => _PayDialogState();
}

class _PayDialogState extends State<_PayDialog> {
  bool credit = false;
  String method = 'cash';
  late final TextEditingController amount = TextEditingController(text: Cents.format(widget.total));
  String? error;

  @override
  void dispose() {
    amount.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: Text('Total ${Cents.pretty(widget.total)}'),
      content: Column(mainAxisSize: MainAxisSize.min, children: [
        SegmentedButton<bool>(
          segments: [const ButtonSegment(value: false, label: Text('Paid in full')), ButtonSegment(value: true, label: const Text('On credit'), enabled: widget.hasCustomer)],
          selected: {credit},
          onSelectionChanged: (s) => setState(() {
            credit = s.first;
            amount.text = credit ? '0.00' : Cents.format(widget.total);
          }),
        ),
        const SizedBox(height: 12),
        DropdownButtonFormField<String>(
          initialValue: method,
          decoration: const InputDecoration(labelText: 'Method'),
          items: const [DropdownMenuItem(value: 'cash', child: Text('Cash')), DropdownMenuItem(value: 'card', child: Text('Card / POS')), DropdownMenuItem(value: 'transfer', child: Text('Transfer'))],
          onChanged: (v) => setState(() => method = v ?? 'cash'),
        ),
        TextField(key: const Key('pay-amount'), controller: amount, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: InputDecoration(labelText: credit ? 'Paid now' : 'Amount received', errorText: error)),
      ]),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: const Text('Cancel')),
        FilledButton(
          key: const Key('pay-confirm'),
          onPressed: () {
            try {
              Navigator.pop(context, _PayChoice(credit, method, Cents.parse(amount.text)));
            } on FormatException {
              setState(() => error = 'Enter an amount like 1500 or 1500.50');
            }
          },
          child: const Text('Confirm'),
        ),
      ],
    );
  }
}

class _ReceiptDialog extends StatelessWidget {
  const _ReceiptDialog(this.sale);
  final ProvisionalSale sale;
  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('Sale saved'),
      content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
        const Text('PROVISIONAL — the receipt number is issued when this sale reaches the server.', style: TextStyle(fontStyle: FontStyle.italic)),
        const SizedBox(height: 8),
        for (final l in sale.lines) Text('${l.quantity} × ${l.name}   ${Cents.pretty(l.unitPrice * l.quantity)}'),
        const Divider(),
        Text('Total ${Cents.pretty(sale.total)}', style: const TextStyle(fontWeight: FontWeight.bold)),
        if (sale.change > 0) Text('Change ${Cents.pretty(sale.change)}', key: const Key('change')),
      ]),
      actions: [FilledButton(key: const Key('receipt-ok'), onPressed: () => Navigator.pop(context), child: const Text('New sale'))],
    );
  }
}

class _CustomerPicker extends StatefulWidget {
  const _CustomerPicker(this.customers);
  final List<Map<String, Object?>> customers;
  @override
  State<_CustomerPicker> createState() => _CustomerPickerState();
}

class _CustomerPickerState extends State<_CustomerPicker> {
  String q = '';
  @override
  Widget build(BuildContext context) {
    final shown = widget.customers.where((c) => '${c['name']} ${c['phone'] ?? ''}'.toLowerCase().contains(q.toLowerCase())).toList();
    return AlertDialog(
      title: const Text('Customer'),
      content: SizedBox(
        width: 360,
        height: 360,
        child: Column(children: [
          TextField(decoration: const InputDecoration(hintText: 'Search'), onChanged: (v) => setState(() => q = v)),
          Expanded(
            child: ListView(children: [
              for (final c in shown)
                ListTile(key: Key('pick-${c['id']}'), title: Text(c['name'] as String), subtitle: Text((c['phone'] as String?) ?? ''), onTap: () => Navigator.pop(context, c)),
            ]),
          ),
        ]),
      ),
    );
  }
}
