import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';
import '../common/online.dart';

/// Team management (owner). Needs a plan that includes staff: when it does not, the server's own message is shown.
class StaffScreen extends ConsumerStatefulWidget {
  const StaffScreen({super.key});
  @override
  ConsumerState<StaffScreen> createState() => _StaffScreenState();
}

class _StaffScreenState extends ConsumerState<StaffScreen> {
  int _rev = 0;
  void _reload() => setState(() => _rev++);

  @override
  Widget build(BuildContext context) {
    final w = ref.watch(sessionProvider).work!;
    return OnlineData<List<Map<String, dynamic>>>(
      key: ValueKey(_rev),
      load: () async => ((await w.client.get('/staff')).data as List).cast<Map<String, dynamic>>(),
      builder: (context, rows, reload) => Scaffold(
        floatingActionButton: FloatingActionButton.extended(key: const Key('invite-staff'), onPressed: _invite, icon: const Icon(Icons.person_add_alt), label: const Text('Invite')),
        body: ListView(children: [
          for (final s in rows)
            ListTile(
              key: Key('staff-${s['id']}'),
              leading: CircleAvatar(child: Text('${s['name'] ?? '?'}'.isEmpty ? '?' : '${s['name']}'[0].toUpperCase())),
              title: Text('${s['name']}'),
              subtitle: Text('${s['email']} · ${s['role']} · ${s['status']}'),
              trailing: s['role'] == 'owner'
                  ? const Text('Owner')
                  : PopupMenuButton<String>(
                      key: Key('staff-menu-${s['id']}'),
                      onSelected: (a) => _act(s, a),
                      itemBuilder: (_) => [
                        if (s['role'] != 'manager') const PopupMenuItem(value: 'manager', child: Text('Make manager')),
                        if (s['role'] != 'cashier') const PopupMenuItem(value: 'cashier', child: Text('Make cashier')),
                        if (s['status'] == 'active') const PopupMenuItem(value: 'inactive', child: Text('Suspend')),
                        if (s['status'] == 'inactive') const PopupMenuItem(value: 'active', child: Text('Reactivate')),
                        if (s['status'] == 'invited') const PopupMenuItem(value: 'resend', child: Text('Resend invitation')),
                        const PopupMenuItem(value: 'remove', child: Text('Remove')),
                      ],
                    ),
            ),
        ]),
      ),
    );
  }

  Future<void> _act(Map<String, dynamic> s, String action) async {
    final c = ref.read(sessionProvider).work!.client;
    try {
      switch (action) {
        case 'manager' || 'cashier':
          await c.send('PATCH', '/staff/${s['id']}', body: {'role': action});
        case 'active' || 'inactive':
          await c.send('PATCH', '/staff/${s['id']}', body: {'status': action});
        case 'resend':
          await c.post('/staff/${s['id']}/resend-invite');
        case 'remove':
          final ok = await showDialog<bool>(
            context: context,
            builder: (_) => AlertDialog(
              title: Text('Remove ${s['name']}?'),
              content: const Text('They lose access to this business immediately. Their past sales stay on record.'),
              actions: [TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Keep')), FilledButton(key: const Key('staff-remove-confirm'), onPressed: () => Navigator.pop(context, true), child: const Text('Remove'))],
            ),
          );
          if (ok != true) return;
          await c.send('DELETE', '/staff/${s['id']}');
      }
      if (mounted) snack(context, 'Done.');
    } catch (e) {
      if (mounted) snack(context, explain(e));
    }
    if (mounted) _reload();
  }

  Future<void> _invite() async {
    final name = TextEditingController();
    final email = TextEditingController();
    String role = 'cashier';
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => StatefulBuilder(
        builder: (context, set) => AlertDialog(
          title: const Text('Invite a team member'),
          content: Column(mainAxisSize: MainAxisSize.min, children: [
            TextField(key: const Key('staff-name'), controller: name, decoration: const InputDecoration(labelText: 'Name')),
            TextField(key: const Key('staff-email'), controller: email, keyboardType: TextInputType.emailAddress, decoration: const InputDecoration(labelText: 'Email')),
            DropdownButtonFormField<String>(
              initialValue: role,
              decoration: const InputDecoration(labelText: 'Role'),
              items: const [DropdownMenuItem(value: 'cashier', child: Text('Cashier — sells, sees sales and customers')), DropdownMenuItem(value: 'manager', child: Text('Manager — also stock, purchases, reports'))],
              onChanged: (v) => set(() => role = v ?? 'cashier'),
            ),
          ]),
          actions: [TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')), FilledButton(key: const Key('staff-send'), onPressed: () => Navigator.pop(context, true), child: const Text('Send invitation'))],
        ),
      ),
    );
    if (ok != true || !mounted) return;
    try {
      await ref.read(sessionProvider).work!.client.post('/staff', body: {'name': name.text.trim(), 'email': email.text.trim(), 'role': role});
      if (mounted) snack(context, 'Invitation sent.');
    } catch (e) {
      if (mounted) snack(context, explain(e));
    }
    if (mounted) _reload();
  }
}
