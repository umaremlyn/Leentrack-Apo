import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';

import '../../app/providers.dart';
import '../../core/money.dart';
import '../../network/api_exception.dart';
import '../common/online.dart';

/// Where a downloaded receipt is written. Replaced in tests.
final receiptSaverProvider = Provider<Future<String> Function(String fileName, List<int> bytes)>((ref) => (name, bytes) async {
      final dir = await getApplicationDocumentsDirectory();
      final f = File(p.join(dir.path, name));
      await f.writeAsBytes(bytes, flush: true);
      return f.path;
    });

/// Online sales history (needs the server's full picture). Receipts are the server's PDF documents.
class SalesScreen extends ConsumerStatefulWidget {
  const SalesScreen({super.key});
  @override
  ConsumerState<SalesScreen> createState() => _SalesScreenState();
}

class _SalesScreenState extends ConsumerState<SalesScreen> {
  List<Map<String, dynamic>> _rows = const [];
  String? _error;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    try {
      final r = await ref.read(sessionProvider).work!.client.get('/sales', query: {'per_page': 50});
      _rows = (r.data as List).cast<Map<String, dynamic>>();
      _error = null;
    } on ApiException catch (e) {
      _error = e.isNetwork ? 'Offline — sales history needs a connection. Sales made on this device are listed under Sync.' : e.message;
    }
    if (mounted) setState(() => _loading = false);
  }

  Future<void> _receipt(Map<String, dynamic> sale) async {
    final messenger = ScaffoldMessenger.of(context);
    try {
      final bytes = await ref.read(sessionProvider).work!.client.getBytes('/sales/${sale['id']}/document', query: {'format': 'pdf'});
      final safe = '${sale['receipt_number']}'.replaceAll(RegExp(r'[^A-Za-z0-9._-]'), '_');
      final path = await ref.read(receiptSaverProvider)('receipt-$safe.pdf', bytes);
      messenger.showSnackBar(SnackBar(content: Text('Receipt saved: $path'), duration: const Duration(seconds: 8)));
    } on ApiException catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(e.isNetwork ? 'Offline — connect to download the receipt.' : e.message)));
    } on FileSystemException catch (e) {
      messenger.showSnackBar(SnackBar(content: Text('Could not save the file: ${e.message}')));
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Center(child: CircularProgressIndicator());
    if (_error != null) return Center(child: Padding(padding: const EdgeInsets.all(24), child: Column(mainAxisSize: MainAxisSize.min, children: [Text(_error!, textAlign: TextAlign.center), TextButton(onPressed: _load, child: const Text('Retry'))])));
    if (_rows.isEmpty) return const Center(child: Text('No sales yet'));
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView.builder(
        itemCount: _rows.length,
        itemBuilder: (_, i) {
          final s = _rows[i];
          final total = Cents.parse('${s['total_amount']}');
          return ListTile(
            key: Key('sale-${s['id']}'),
            title: Text('${s['receipt_number']} · ${Cents.pretty(total)}'),
            subtitle: Text([s['customer_name'] ?? 'Walk-in', s['payment_status'], '${s['created_at']}'.split('T').first].join(' · ')),
            onTap: () async {
              await Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => SaleDetailPage(saleId: s['id'] as String)));
              await _load();
            },
            trailing: IconButton(key: Key('receipt-${s['id']}'), tooltip: 'Save receipt (PDF)', icon: const Icon(Icons.picture_as_pdf_outlined), onPressed: () => _receipt(s)),
          );
        },
      ),
    );
  }
}


/// One sale: what was sold, what was paid, and (managers) voiding it. Voiding restores stock and reverses loyalty; the SHOP hands the money back —
/// the app tells you how much, it does not move money.
class SaleDetailPage extends ConsumerStatefulWidget {
  const SaleDetailPage({super.key, required this.saleId});
  final String saleId;
  @override
  ConsumerState<SaleDetailPage> createState() => _SaleDetailPageState();
}

class _SaleDetailPageState extends ConsumerState<SaleDetailPage> {
  int _rev = 0;

  Future<void> _void() async {
    final reason = TextEditingController();
    String? error;
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => StatefulBuilder(
        builder: (context, set) => AlertDialog(
          title: const Text('Void this sale?'),
          content: Column(mainAxisSize: MainAxisSize.min, children: [
            const Text('Stock goes back on the shelf and the customer\'s points and spend are reversed. This cannot be undone.'),
            TextField(key: const Key('void-reason'), controller: reason, decoration: InputDecoration(labelText: 'Reason (kept in the audit log)', errorText: error)),
          ]),
          actions: [
            TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Keep sale')),
            FilledButton(
              key: const Key('void-confirm'),
              onPressed: () {
                if (reason.text.trim().length < 3) {
                  set(() => error = 'Give a reason (at least 3 characters).');
                  return;
                }
                Navigator.pop(context, true);
              },
              child: const Text('Void sale'),
            ),
          ],
        ),
      ),
    );
    if (ok != true || !mounted) return;
    try {
      final r = await ref.read(sessionProvider).work!.client.post('/sales/${widget.saleId}/void', body: {'reason': reason.text.trim()});
      final d = Map<String, dynamic>.from(r.data as Map);
      if (mounted) {
        await showDialog<void>(
          context: context,
          builder: (_) => AlertDialog(
            title: const Text('Sale voided'),
            content: Text('Hand back ${Cents.pretty(Cents.parse('${d['refund_due'] ?? '0'}'))} to the customer if they already paid. The app does not move money.', key: const Key('refund-due')),
            actions: [FilledButton(key: const Key('void-ok'), onPressed: () => Navigator.pop(context), child: const Text('OK'))],
          ),
        );
      }
    } on ApiException catch (e) {
      if (mounted) snack(context, explain(e));
    }
    if (mounted) setState(() => _rev++);
  }

  @override
  Widget build(BuildContext context) {
    final w = ref.watch(sessionProvider).work!;
    return Scaffold(
      appBar: AppBar(title: const Text('Sale')),
      body: OnlineData<Map<String, dynamic>>(
        key: ValueKey(_rev),
        load: () async => Map<String, dynamic>.from((await w.client.get('/sales/${widget.saleId}')).data as Map),
        builder: (context, s, reload) {
          final items = (s['items'] as List).cast<Map<String, dynamic>>();
          final voided = s['voided_at'] != null || s['status'] == 'voided';
          String m(String k) => Cents.pretty(Cents.parse('${s[k] ?? '0'}'));
          final cust = s['customer'] is Map ? (s['customer'] as Map)['name'] : null;
          return ListView(padding: const EdgeInsets.all(12), children: [
            Text('${s['receipt_number']}', style: Theme.of(context).textTheme.titleLarge),
            Text('${voided ? 'VOIDED' : s['status']} · ${cust ?? 'Walk-in'} · ${s['cashier_name']} · ${s['outlet_name']}', key: const Key('sale-status')),
            if (voided) Text('Reason: ${s['void_reason']}'),
            const SizedBox(height: 8),
            for (final i in items) ListTile(dense: true, title: Text('${i['name']}'), subtitle: Text('${i['quantity']} × ${Cents.pretty(Cents.parse('${i['unit_price']}'))}'), trailing: Text(Cents.pretty(Cents.parse('${i['total_price']}')))),
            const Divider(),
            _line('Subtotal', m('subtotal')),
            if (Cents.parse('${s['discount_amount'] ?? '0'}') > 0) _line('Discount', '− ${m('discount_amount')}'),
            if (Cents.parse('${s['tax_amount'] ?? '0'}') > 0) _line('Tax', m('tax_amount')),
            _line('Total', m('total_amount'), bold: true),
            _line('Paid so far', m('paid_so_far')),
            if (Cents.parse('${s['balance_due'] ?? '0'}') > 0 && !voided) _line('Still owed', m('balance_due')),
            const SizedBox(height: 16),
            if (w.isManager && !voided) OutlinedButton.icon(key: const Key('void-sale'), onPressed: _void, icon: const Icon(Icons.block), label: const Text('Void sale')),
            if (w.isManager && !voided) const Padding(padding: EdgeInsets.only(top: 8), child: Text('Editing a sale is not available in the app yet; void it and record it again, or use the web app.', style: TextStyle(fontSize: 12))),
          ]);
        },
      ),
    );
  }

  Widget _line(String a, String b, {bool bold = false}) {
    final st = bold ? const TextStyle(fontWeight: FontWeight.bold) : null;
    return Padding(padding: const EdgeInsets.symmetric(vertical: 2), child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [Text(a, style: st), Text(b, style: st)]));
  }
}
