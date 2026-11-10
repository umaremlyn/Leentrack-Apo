import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';
import '../common/online.dart';

const _periods = {'today': 'Today', 'week': 'Last 7 days', 'month': 'This month', 'lastMonth': 'Last month'};

/// Every server report in one generic viewer: the server says which reports exist, which columns they have, and the totals.
class ReportsScreen extends ConsumerStatefulWidget {
  const ReportsScreen({super.key});
  @override
  ConsumerState<ReportsScreen> createState() => _ReportsScreenState();
}

class _ReportsScreenState extends ConsumerState<ReportsScreen> {
  String? _report;
  String _period = 'month';

  static String label(String key) => key.isEmpty ? key : key[0].toUpperCase() + key.substring(1).replaceAll('_', ' ');

  @override
  Widget build(BuildContext context) {
    final client = ref.watch(sessionProvider).work!.client;
    return OnlineData<List<Map<String, dynamic>>>(
      load: () async => ((await client.get('/reports')).data as List).cast<Map<String, dynamic>>(),
      builder: (context, list, _) {
        final allowed = list.where((r) => r['allowed'] == true).toList();
        if (allowed.isEmpty) return const Center(child: Text('No reports are available on your plan or role.'));
        final current = allowed.firstWhere((r) => r['key'] == _report, orElse: () => allowed.first);
        final key = current['key'] as String;
        final timed = current['timed'] == true;
        return Column(children: [
          Padding(
            padding: const EdgeInsets.all(8),
            child: Row(children: [
              Expanded(
                child: DropdownButton<String>(
                  key: const Key('report-pick'),
                  isExpanded: true,
                  value: key,
                  items: [for (final r in allowed) DropdownMenuItem(value: r['key'] as String, child: Text(label(r['key'] as String)))],
                  onChanged: (v) => setState(() => _report = v),
                ),
              ),
              if (timed) ...[
                const SizedBox(width: 12),
                DropdownButton<String>(
                  key: const Key('report-period'),
                  value: _period,
                  items: [for (final e in _periods.entries) DropdownMenuItem(value: e.key, child: Text(e.value))],
                  onChanged: (v) => setState(() => _period = v ?? 'month'),
                ),
              ],
            ]),
          ),
          Expanded(child: _ReportBody(key: ValueKey('$key/${timed ? _period : ''}'), name: key, query: {'per_page': 100, if (timed) 'period': _period})),
        ]);
      },
    );
  }
}

class _ReportBody extends ConsumerWidget {
  const _ReportBody({super.key, required this.name, required this.query});
  final String name;
  final Map<String, dynamic> query;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final client = ref.watch(sessionProvider).work!.client;
    return OnlineData<({List<Map<String, dynamic>> rows, Map<String, dynamic> meta})>(
      load: () async {
        final r = await client.get('/reports/$name', query: query);
        return (rows: (r.data as List).cast<Map<String, dynamic>>(), meta: r.meta);
      },
      builder: (context, d, reload) {
        final cols = ((d.meta['columns'] as List?) ?? (d.rows.isEmpty ? const [] : d.rows.first.keys.toList())).cast<String>();
        final summary = Map<String, dynamic>.from(d.meta['summary'] as Map? ?? {});
        final chips = [for (final e in summary.entries) if (e.value is num || e.value is String) '${_ReportsScreenState.label(e.key)}: ${e.value}'];
        return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          if (chips.isNotEmpty) Padding(padding: const EdgeInsets.symmetric(horizontal: 12), child: Wrap(spacing: 8, runSpacing: 4, children: [for (final c in chips) Chip(label: Text(c, style: const TextStyle(fontSize: 12)))])),
          Expanded(
            child: d.rows.isEmpty
                ? const Center(child: Text('No data for this period'))
                : SingleChildScrollView(
                    child: SingleChildScrollView(
                      scrollDirection: Axis.horizontal,
                      child: DataTable(
                        key: const Key('report-table'),
                        columns: [for (final c in cols) DataColumn(label: Text(_ReportsScreenState.label(c)))],
                        rows: [for (final r in d.rows) DataRow(cells: [for (final c in cols) DataCell(Text('${r[c] ?? ''}'))])],
                      ),
                    ),
                  ),
          ),
          if ((d.meta['total'] as int? ?? d.rows.length) > d.rows.length)
            Padding(padding: const EdgeInsets.all(8), child: Text('Showing the first ${d.rows.length} of ${d.meta['total']} rows. Use the web app or the CSV export for the rest.')),
        ]);
      },
    );
  }
}
