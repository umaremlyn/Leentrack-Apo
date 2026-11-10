import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';
import '../../core/money.dart';
import '../common/online.dart';

/// Business, loyalty and alert settings (managers/owner may change them; the server enforces who) and the signed-in person's own account.
class SettingsScreen extends ConsumerWidget {
  const SettingsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final w = ref.watch(sessionProvider).work!;
    return Scaffold(
      appBar: AppBar(title: const Text('Settings')),
      body: ListView(padding: const EdgeInsets.all(12), children: [
        _BusinessCard(canEdit: w.isManager),
        if (w.isManager) const _LoyaltyCard(),
        if (w.isManager) const _AlertsCard(),
        const _AccountCard(),
      ]),
    );
  }
}

Widget _card(BuildContext context, String title, Widget child) => Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(title, style: Theme.of(context).textTheme.titleMedium), const SizedBox(height: 8), child])),
    );

class _BusinessCard extends ConsumerStatefulWidget {
  const _BusinessCard({required this.canEdit});
  final bool canEdit;
  @override
  ConsumerState<_BusinessCard> createState() => _BusinessCardState();
}

class _BusinessCardState extends ConsumerState<_BusinessCard> {
  final _name = TextEditingController();
  final _tax = TextEditingController();
  String? _loadedFor;

  @override
  void dispose() {
    _name.dispose();
    _tax.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final w = ref.watch(sessionProvider).work!;
    final path = '/businesses/${w.membership.businessId}';
    return _card(
      context,
      'Business',
      OnlineData<Map<String, dynamic>>(
        load: () async => Map<String, dynamic>.from((await w.client.get(path)).data as Map),
        builder: (context, b, _) {
          if (_loadedFor != '${b['id']}${b['tax_rate']}${b['name']}') {
            _loadedFor = '${b['id']}${b['tax_rate']}${b['name']}';
            _name.text = '${b['name']}';
            _tax.text = '${b['tax_rate']}';
          }
          return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            TextField(key: const Key('biz-name'), controller: _name, enabled: widget.canEdit, decoration: const InputDecoration(labelText: 'Business name')),
            TextField(key: const Key('biz-tax'), controller: _tax, enabled: widget.canEdit, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: const InputDecoration(labelText: 'Tax rate (%) added to every sale')),
            const SizedBox(height: 4),
            Text('Currency ${b['currency']} · plan ${b['effective_plan']}', style: Theme.of(context).textTheme.bodySmall),
            if (widget.canEdit)
              Align(
                alignment: Alignment.centerRight,
                child: FilledButton(
                  key: const Key('biz-save'),
                  onPressed: () async {
                    final pct = double.tryParse(_tax.text.trim());
                    if (pct == null || pct < 0 || pct > 100 || _name.text.trim().length < 2) {
                      snack(context, 'Enter a name and a tax rate between 0 and 100.');
                      return;
                    }
                    try {
                      await w.client.send('PATCH', path, body: {'name': _name.text.trim(), 'tax_rate': _tax.text.trim()});
                      if (context.mounted) snack(context, 'Saved. The till picks up the new tax rate on its next sync.');
                      ref.read(syncProvider.notifier).trigger(); // so this device's till uses the new rate straight away
                    } catch (e) {
                      if (context.mounted) snack(context, explain(e));
                    }
                  },
                  child: const Text('Save'),
                ),
              ),
          ]);
        },
      ),
    );
  }
}

class _LoyaltyCard extends ConsumerStatefulWidget {
  const _LoyaltyCard();
  @override
  ConsumerState<_LoyaltyCard> createState() => _LoyaltyCardState();
}

class _LoyaltyCardState extends ConsumerState<_LoyaltyCard> {
  bool _on = false;
  final _spend = TextEditingController();
  final _value = TextEditingController();
  bool _loaded = false;

  @override
  void dispose() {
    _spend.dispose();
    _value.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final w = ref.watch(sessionProvider).work!;
    final path = '/businesses/${w.membership.businessId}/loyalty-settings';
    return _card(
      context,
      'Loyalty points',
      OnlineData<Map<String, dynamic>>(
        load: () async => Map<String, dynamic>.from((await w.client.get(path)).data as Map),
        builder: (context, d, _) {
          if (!_loaded) {
            _loaded = true;
            _on = d['enabled'] == true;
            _spend.text = '${d['spend_per_point']}';
            _value.text = '${d['point_value']}';
          }
          return StatefulBuilder(
            builder: (context, set) => Column(children: [
              SwitchListTile(key: const Key('loyalty-on'), contentPadding: EdgeInsets.zero, title: const Text('Customers earn and spend points'), value: _on, onChanged: (v) => set(() => _on = v)),
              TextField(key: const Key('loyalty-spend'), controller: _spend, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: const InputDecoration(labelText: 'Spend this much to earn 1 point')),
              TextField(key: const Key('loyalty-value'), controller: _value, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: const InputDecoration(labelText: 'Each point is worth')),
              Align(
                alignment: Alignment.centerRight,
                child: FilledButton(
                  key: const Key('loyalty-save'),
                  onPressed: () async {
                    try {
                      await w.client.send('PUT', path, body: {'enabled': _on, 'spend_per_point': Cents.format(Cents.parse(_spend.text)), 'point_value': Cents.format(Cents.parse(_value.text))});
                      if (context.mounted) snack(context, 'Saved.');
                      ref.read(syncProvider.notifier).trigger();
                    } on FormatException {
                      if (context.mounted) snack(context, 'Enter amounts like 100 or 100.50.');
                    } catch (e) {
                      if (context.mounted) snack(context, explain(e));
                    }
                  },
                  child: const Text('Save'),
                ),
              ),
            ]),
          );
        },
      ),
    );
  }
}

class _AlertsCard extends ConsumerStatefulWidget {
  const _AlertsCard();
  @override
  ConsumerState<_AlertsCard> createState() => _AlertsCardState();
}

class _AlertsCardState extends ConsumerState<_AlertsCard> {
  bool _on = false;
  String _freq = 'daily';
  final _email = TextEditingController();
  bool _loaded = false;

  @override
  void dispose() {
    _email.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final w = ref.watch(sessionProvider).work!;
    final path = '/businesses/${w.membership.businessId}/alert-settings';
    return _card(
      context,
      'Low-stock e-mail alerts',
      OnlineData<Map<String, dynamic>>(
        load: () async => Map<String, dynamic>.from((await w.client.get(path)).data as Map),
        builder: (context, d, _) {
          if (!_loaded) {
            _loaded = true;
            _on = d['enabled'] == true;
            _freq = '${d['frequency']}';
            _email.text = '${d['email'] ?? d['default_email'] ?? ''}'; // the address alerts will really go to; the server wants it spelled out when enabling
          }
          return StatefulBuilder(
            builder: (context, set) => Column(children: [
              SwitchListTile(key: const Key('alert-on'), contentPadding: EdgeInsets.zero, title: const Text('E-mail me when stock runs low'), value: _on, onChanged: (v) => set(() => _on = v)),
              TextField(key: const Key('alert-email'), controller: _email, keyboardType: TextInputType.emailAddress, decoration: const InputDecoration(labelText: 'Send to')),
              DropdownButtonFormField<String>(
                initialValue: _freq,
                decoration: const InputDecoration(labelText: 'At most'),
                items: const [DropdownMenuItem(value: 'daily', child: Text('Once a day')), DropdownMenuItem(value: 'hourly', child: Text('Once an hour'))],
                onChanged: (v) => set(() => _freq = v ?? 'daily'),
              ),
              Align(
                alignment: Alignment.centerRight,
                child: FilledButton(
                  key: const Key('alert-save'),
                  onPressed: () async {
                    if (_on && !_email.text.contains('@')) {
                      snack(context, 'Enter the e-mail address the alerts should go to.');
                      return;
                    }
                    try {
                      await w.client.send('PUT', path, body: {'enabled': _on, 'frequency': _freq, 'email': _email.text.trim().isEmpty ? null : _email.text.trim()});
                      if (context.mounted) snack(context, 'Saved.');
                    } catch (e) {
                      if (context.mounted) snack(context, explain(e));
                    }
                  },
                  child: const Text('Save'),
                ),
              ),
            ]),
          );
        },
      ),
    );
  }
}

class _AccountCard extends ConsumerStatefulWidget {
  const _AccountCard();
  @override
  ConsumerState<_AccountCard> createState() => _AccountCardState();
}

class _AccountCardState extends ConsumerState<_AccountCard> {
  int _rev = 0;

  Future<void> _changePassword() async {
    final cur = TextEditingController();
    final next = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('Change password'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(key: const Key('pw-current'), controller: cur, obscureText: true, decoration: const InputDecoration(labelText: 'Current password')),
          TextField(key: const Key('pw-new'), controller: next, obscureText: true, decoration: const InputDecoration(labelText: 'New password')),
        ]),
        actions: [TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')), FilledButton(key: const Key('pw-save'), onPressed: () => Navigator.pop(context, true), child: const Text('Change'))],
      ),
    );
    if (ok != true || !mounted) return;
    try {
      await ref.read(sessionProvider).work!.client.post('/me/change-password', body: {'current_password': cur.text, 'new_password': next.text});
      if (mounted) snack(context, 'Password changed.');
    } catch (e) {
      if (mounted) snack(context, explain(e)); // the server explains weak passwords and wrong current passwords
    }
  }

  @override
  Widget build(BuildContext context) {
    final w = ref.watch(sessionProvider).work!;
    return _card(
      context,
      'My account',
      Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text('${w.user.name ?? ''}  ${w.user.email}'.trim()),
        Text('Role here: ${w.role}', style: Theme.of(context).textTheme.bodySmall),
        const SizedBox(height: 8),
        OutlinedButton(key: const Key('change-password'), onPressed: _changePassword, child: const Text('Change password')),
        const SizedBox(height: 12),
        const Text('Devices signed in'),
        OnlineData<List<Map<String, dynamic>>>(
          key: ValueKey(_rev),
          load: () async => ((await w.client.get('/me/sessions')).data as List).cast<Map<String, dynamic>>(),
          builder: (context, rows, _) => Column(children: [
            for (final s in rows)
              ListTile(
                key: Key('session-${s['id']}'),
                contentPadding: EdgeInsets.zero,
                dense: true,
                title: Text('${s['device_name'] ?? s['platform']}${s['current'] == true ? ' (this device)' : ''}'),
                subtitle: Text('Last used ${s['last_used_at']}'),
                trailing: s['current'] == true
                    ? null
                    : TextButton(
                        key: Key('revoke-${s['id']}'),
                        onPressed: () async {
                          try {
                            await w.client.send('DELETE', '/me/sessions/${s['id']}');
                            if (mounted) setState(() => _rev++);
                          } catch (e) {
                            if (context.mounted) snack(context, explain(e));
                          }
                        },
                        child: const Text('Sign out'),
                      ),
              ),
          ]),
        ),
      ]),
    );
  }
}
