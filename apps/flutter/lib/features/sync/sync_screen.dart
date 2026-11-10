import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';
import '../../network/api_exception.dart';
import '../../sync/sync_engine.dart';

String describe(SyncState s) => switch (s) {
      SyncState.idle => 'All synced',
      SyncState.syncing => 'Syncing…',
      SyncState.offline => 'Offline — changes are saved on this device',
      SyncState.attention => 'Some sales need attention',
      SyncState.failed => 'Sync problem',
    };

/// A thin bar shown above every screen: what the queue is doing, and a way into the details.
class SyncBar extends ConsumerWidget {
  const SyncBar({super.key, required this.onOpen});
  final VoidCallback onOpen;
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = ref.watch(syncProvider);
    final scheme = Theme.of(context).colorScheme;
    final color = switch (s.state) {
      SyncState.attention || SyncState.failed => scheme.errorContainer,
      SyncState.offline => scheme.tertiaryContainer,
      _ => scheme.surfaceContainerHighest,
    };
    final extra = [if (s.pending > 0) '${s.pending} waiting', if (s.attention > 0) '${s.attention} need attention'].join(' · ');
    return Material(
      color: color,
      child: InkWell(
        key: const Key('sync-bar'),
        onTap: onOpen,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
          child: Row(children: [
            Icon(s.state == SyncState.syncing ? Icons.sync : (s.state == SyncState.offline ? Icons.cloud_off : Icons.cloud_done_outlined), size: 18),
            const SizedBox(width: 8),
            Expanded(child: Text([describe(s.state), if (extra.isNotEmpty) extra].join(' · '), key: const Key('sync-text'), style: Theme.of(context).textTheme.bodySmall)),
          ]),
        ),
      ),
    );
  }
}

class SyncScreen extends ConsumerStatefulWidget {
  const SyncScreen({super.key});
  @override
  ConsumerState<SyncScreen> createState() => _SyncScreenState();
}

class _SyncScreenState extends ConsumerState<SyncScreen> {
  List<Map<String, dynamic>> _inbox = const [];
  String? _inboxError;
  bool _loading = false;

  @override
  void initState() {
    super.initState();
    if (ref.read(sessionProvider).work!.isManager) _loadInbox();
  }

  Future<void> _loadInbox() async {
    setState(() => _loading = true);
    try {
      final r = await ref.read(sessionProvider).work!.client.get('/sync/operations', query: {'status': 'attention'});
      _inbox = (r.data as List).cast<Map<String, dynamic>>();
      _inboxError = null;
    } on ApiException catch (e) {
      _inboxError = e.isNetwork ? 'Offline — the manager inbox needs a connection.' : e.message;
    }
    if (mounted) setState(() => _loading = false);
  }

  Future<void> _act(Map<String, dynamic> op, String action, {Map<String, dynamic>? body}) async {
    try {
      await ref.read(sessionProvider).work!.client.post('/sync/operations/${op['id']}/$action', body: body ?? {});
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(action == 'discard' ? 'Discarded.' : 'Done.')));
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message), duration: const Duration(seconds: 6)));
    }
    await _loadInbox();
    ref.read(syncProvider.notifier).trigger();
  }

  Future<void> _discard(Map<String, dynamic> op) async {
    final reason = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('Discard this operation?'),
        content: TextField(controller: reason, decoration: const InputDecoration(labelText: 'Reason (kept in the audit log)')),
        actions: [TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')), FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Discard'))],
      ),
    );
    if (ok == true) await _act(op, 'discard', body: {'reason': reason.text});
  }

  @override
  Widget build(BuildContext context) {
    final w = ref.watch(sessionProvider).work!;
    final s = ref.watch(syncProvider);
    final queue = w.db.outbox();
    return Scaffold(
      appBar: AppBar(title: const Text('Sync'), actions: [IconButton(key: const Key('sync-now'), icon: const Icon(Icons.sync), tooltip: 'Sync now', onPressed: () => ref.read(syncProvider.notifier).trigger())]),
      body: ListView(children: [
        ListTile(title: Text(describe(s.state)), subtitle: Text(s.lastSync == null ? 'Never synced' : 'Last sync ${s.lastSync!.toLocal()}')),
        if (s.lastReport?.failure != null) Padding(padding: const EdgeInsets.symmetric(horizontal: 16), child: Text(s.lastReport!.failure!, style: TextStyle(color: Theme.of(context).colorScheme.error))),
        const Divider(),
        const Padding(padding: EdgeInsets.fromLTRB(16, 8, 16, 0), child: Text('On this device', style: TextStyle(fontWeight: FontWeight.bold))),
        if (queue.isEmpty) const ListTile(title: Text('Nothing waiting to be sent')),
        for (final o in queue)
          ListTile(
            key: Key('op-${o['id']}'),
            leading: Icon(switch (o['status']) { 'conflict' => Icons.warning_amber, 'rejected' => Icons.block, _ => Icons.schedule }),
            title: Text(o['summary'] as String? ?? ''),
            subtitle: Text([o['type'], o['status'], if (o['message'] != null) o['message']].join(' · ')),
          ),
        if (w.isManager) ...[
          const Divider(),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
            child: Row(children: [const Expanded(child: Text('Needs a manager (all devices)', style: TextStyle(fontWeight: FontWeight.bold))), IconButton(icon: const Icon(Icons.refresh), onPressed: _loadInbox)]),
          ),
          if (_loading) const LinearProgressIndicator(),
          if (_inboxError != null) ListTile(title: Text(_inboxError!)),
          if (_inboxError == null && !_loading && _inbox.isEmpty) const ListTile(title: Text('Nothing needs attention')),
          for (final op in _inbox)
            Card(
              key: Key('inbox-${op['id']}'),
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text('${op['type']} · ${op['status']} · ${op['code'] ?? ''}', style: const TextStyle(fontWeight: FontWeight.bold)),
                  if (op['message'] != null) Text('${op['message']}'),
                  Wrap(spacing: 8, children: [
                    TextButton(onPressed: () => _act(op, 'retry'), child: const Text('Retry')),
                    if (op['code'] == 'price_changed') TextButton(onPressed: () => _act(op, 'accept-prices'), child: const Text('Accept new prices')),
                    TextButton(onPressed: () => _discard(op), child: const Text('Discard')),
                  ]),
                ]),
              ),
            ),
        ],
      ]),
    );
  }
}
