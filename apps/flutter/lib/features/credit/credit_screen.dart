import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:uuid/uuid.dart';

import '../../app/providers.dart';
import '../../auth/authenticated_client.dart';
import '../../core/money.dart';
import '../common/online.dart';

typedef CreditData = ({List<Map<String, dynamic>> customers, Map<String, dynamic> summary});

/// Money customers owe from credit sales, with repayments. Online only: balances must be the server's.
class CreditScreen extends ConsumerWidget {
  const CreditScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final client = ref.watch(sessionProvider).work!.client;
    return OnlineData<CreditData>(
      load: () async {
        final r = await client.get('/credit', query: {'per_page': 100});
        return (customers: (r.data as List).cast<Map<String, dynamic>>(), summary: Map<String, dynamic>.from(r.meta['summary'] as Map? ?? {}));
      },
      builder: (context, d, reload) {
        if (d.customers.isEmpty) return const Center(child: Text('Nobody owes you anything'));
        return RefreshIndicator(
          onRefresh: reload,
          child: ListView(children: [
            ListTile(
              key: const Key('credit-summary'),
              title: Text('Owed in total: ${Cents.pretty(Cents.parse('${d.summary['total_owed'] ?? '0'}'))}'),
              subtitle: Text('Overdue: ${Cents.pretty(Cents.parse('${d.summary['overdue_owed'] ?? '0'}'))} · ${d.summary['customers'] ?? d.customers.length} customer(s)'),
            ),
            const Divider(height: 1),
            for (final c in d.customers)
              ExpansionTile(
                key: Key('debtor-${c['id']}'),
                title: Text('${c['name']}'),
                subtitle: Text('Owes ${Cents.pretty(Cents.parse('${c['total_owed']}'))}${c['is_overdue'] == true ? ' · overdue' : ''}'),
                children: [
                  for (final s in (c['sales'] as List).cast<Map<String, dynamic>>())
                    ListTile(
                      key: Key('debt-${s['id']}'),
                      title: Text('${s['receipt_number']} · balance ${Cents.pretty(Cents.parse('${s['balance_due']}'))}'),
                      subtitle: Text('Total ${Cents.pretty(Cents.parse('${s['total_amount']}'))} · ${s['days_old']} day(s) old'),
                      trailing: FilledButton.tonal(key: Key('repay-${s['id']}'), onPressed: () => _repay(context, client, s, reload), child: const Text('Repay')),
                    ),
                ],
              ),
          ]),
        );
      },
    );
  }

  Future<void> _repay(BuildContext context, AuthenticatedClient client, Map<String, dynamic> sale, Future<void> Function() reload) async {
    final balance = Cents.parse('${sale['balance_due']}');
    final amount = TextEditingController(text: Cents.format(balance));
    // one id per dialog: pressing Confirm twice (or retrying after a lost answer) can never record two repayments
    final opId = const Uuid().v4();
    String method = 'cash';
    String? error;
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => StatefulBuilder(
        builder: (context, set) => AlertDialog(
          title: Text('Repayment on ${sale['receipt_number']}'),
          content: Column(mainAxisSize: MainAxisSize.min, children: [
            Text('Balance ${Cents.pretty(balance)}'),
            TextField(key: const Key('repay-amount'), controller: amount, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: InputDecoration(labelText: 'Amount received', errorText: error)),
            DropdownButtonFormField<String>(
              initialValue: method,
              decoration: const InputDecoration(labelText: 'Method'),
              items: const [DropdownMenuItem(value: 'cash', child: Text('Cash')), DropdownMenuItem(value: 'card', child: Text('Card / POS')), DropdownMenuItem(value: 'bank_transfer', child: Text('Transfer'))],
              onChanged: (v) => set(() => method = v ?? 'cash'),
            ),
          ]),
          actions: [
            TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')),
            FilledButton(
              key: const Key('repay-confirm'),
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
              child: const Text('Confirm'),
            ),
          ],
        ),
      ),
    );
    if (ok != true || !context.mounted) return;
    try {
      await client.post('/sales/${sale['id']}/repayments', body: {'amount': Cents.format(Cents.parse(amount.text)), 'method': method, 'client_operation_id': opId});
      if (context.mounted) snack(context, 'Repayment recorded.');
      await reload();
    } catch (e) {
      if (context.mounted) snack(context, explain(e));
    }
  }
}
