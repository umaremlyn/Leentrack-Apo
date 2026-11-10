import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';
import '../../auth/authenticated_client.dart';
import '../../core/money.dart';
import '../common/online.dart';

typedef ExpenseData = ({List<Map<String, dynamic>> rows, Map<String, dynamic> summary});

/// Expenses list and entry. Online here (the offline queue can also carry `expense.create`, see docs/14, but entering costs is a back-office job).
class ExpensesScreen extends ConsumerWidget {
  const ExpensesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final client = ref.watch(sessionProvider).work!.client;
    return OnlineData<ExpenseData>(
      load: () async {
        final r = await client.get('/expenses', query: {'per_page': 100});
        return (rows: (r.data as List).cast<Map<String, dynamic>>(), summary: Map<String, dynamic>.from(r.meta['summary'] as Map? ?? {}));
      },
      builder: (context, d, reload) => Scaffold(
        floatingActionButton: FloatingActionButton.extended(key: const Key('add-expense'), onPressed: () => _add(context, client, reload), icon: const Icon(Icons.add), label: const Text('New expense')),
        body: d.rows.isEmpty
            ? const Center(child: Text('No expenses yet'))
            : RefreshIndicator(
                onRefresh: reload,
                child: ListView(children: [
                  ListTile(key: const Key('expense-total'), title: Text('Total: ${Cents.pretty(Cents.parse('${d.summary['total'] ?? '0'}'))}'), subtitle: Text('${d.summary['count'] ?? d.rows.length} expense(s)')),
                  const Divider(height: 1),
                  for (final e in d.rows)
                    ListTile(
                      key: Key('expense-${e['id']}'),
                      title: Text('${e['description']}'),
                      subtitle: Text([e['category_name'] ?? 'Uncategorised', e['expense_date'], e['payment_status']].join(' · ')),
                      trailing: Text(Cents.pretty(Cents.parse('${e['amount']}'))),
                      onLongPress: () => _delete(context, client, e, reload),
                    ),
                ]),
              ),
      ),
    );
  }

  Future<void> _delete(BuildContext context, AuthenticatedClient client, Map<String, dynamic> e, Future<void> Function() reload) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: Text('Delete "${e['description']}"?'),
        actions: [TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Keep')), FilledButton(key: const Key('expense-delete-confirm'), onPressed: () => Navigator.pop(context, true), child: const Text('Delete'))],
      ),
    );
    if (ok != true || !context.mounted) return;
    try {
      await client.send('DELETE', '/expenses/${e['id']}');
      await reload();
    } catch (err) {
      if (context.mounted) snack(context, explain(err));
    }
  }

  Future<void> _add(BuildContext context, AuthenticatedClient client, Future<void> Function() reload) async {
    List<Map<String, dynamic>> cats = const [];
    try {
      cats = ((await client.get('/expense-categories')).data as List).cast<Map<String, dynamic>>();
    } catch (_) {}
    if (!context.mounted) return;
    final desc = TextEditingController();
    final amount = TextEditingController();
    final date = TextEditingController(text: DateTime.now().toIso8601String().substring(0, 10));
    String? category;
    String? error;
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => StatefulBuilder(
        builder: (context, set) => AlertDialog(
          title: const Text('New expense'),
          content: SingleChildScrollView(
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              TextField(key: const Key('expense-desc'), controller: desc, decoration: const InputDecoration(labelText: 'What was it for?')),
              TextField(key: const Key('expense-amount'), controller: amount, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: InputDecoration(labelText: 'Amount', errorText: error)),
              TextField(controller: date, decoration: const InputDecoration(labelText: 'Date (YYYY-MM-DD)')),
              if (cats.isNotEmpty)
                DropdownButtonFormField<String?>(
                  initialValue: category,
                  decoration: const InputDecoration(labelText: 'Category'),
                  items: [const DropdownMenuItem(value: null, child: Text('Uncategorised')), for (final c in cats) DropdownMenuItem(value: c['id'] as String, child: Text('${c['name']}'))],
                  onChanged: (v) => set(() => category = v),
                ),
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')),
            FilledButton(
              key: const Key('expense-save'),
              onPressed: () {
                try {
                  if (Cents.parse(amount.text) <= 0 || desc.text.trim().isEmpty) throw const FormatException();
                  Navigator.pop(context, true);
                } on FormatException {
                  set(() => error = 'Enter a description and an amount above zero');
                }
              },
              child: const Text('Save'),
            ),
          ],
        ),
      ),
    );
    if (ok != true || !context.mounted) return;
    try {
      await client.post('/expenses', body: {'description': desc.text.trim(), 'amount': Cents.format(Cents.parse(amount.text)), 'expense_date': date.text.trim(), 'category_id': ?category});
      await reload();
    } catch (e) {
      if (context.mounted) snack(context, explain(e));
    }
  }
}
