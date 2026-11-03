import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';

import '../../app/providers.dart';
import '../../core/money.dart';
import '../../network/api_exception.dart';

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
            trailing: IconButton(key: Key('receipt-${s['id']}'), tooltip: 'Save receipt (PDF)', icon: const Icon(Icons.picture_as_pdf_outlined), onPressed: () => _receipt(s)),
          );
        },
      ),
    );
  }
}
