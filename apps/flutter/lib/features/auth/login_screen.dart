import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';
import '../../auth/auth_service.dart';

class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});
  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _email = TextEditingController();
  final _password = TextEditingController();

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final app = ref.watch(sessionProvider);
    return Scaffold(
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 400),
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: AutofillGroup(
              child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                Text('LeenTrack', style: Theme.of(context).textTheme.headlineMedium, textAlign: TextAlign.center),
                const SizedBox(height: 24),
                if (app.message != null)
                  Padding(padding: const EdgeInsets.only(bottom: 12), child: Text(app.message!, key: const Key('login-message'), style: TextStyle(color: Theme.of(context).colorScheme.error))),
                TextField(key: const Key('login-email'), controller: _email, keyboardType: TextInputType.emailAddress, autofillHints: const [AutofillHints.email], decoration: const InputDecoration(labelText: 'Email')),
                const SizedBox(height: 12),
                TextField(
                  key: const Key('login-password'),
                  controller: _password,
                  obscureText: true,
                  autofillHints: const [AutofillHints.password],
                  decoration: const InputDecoration(labelText: 'Password'),
                  onSubmitted: (_) => _submit(),
                ),
                const SizedBox(height: 20),
                FilledButton(key: const Key('login-submit'), onPressed: app.busy ? null : _submit, child: app.busy ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2)) : const Text('Sign in')),
              ]),
            ),
          ),
        ),
      ),
    );
  }

  void _submit() => ref.read(sessionProvider.notifier).login(_email.text, _password.text);
}

class BusinessPicker extends ConsumerWidget {
  const BusinessPicker({super.key});
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(sessionProvider).user;
    final List<Membership> ms = user?.memberships ?? const [];
    return Scaffold(
      appBar: AppBar(title: const Text('Choose a business'), actions: [TextButton(onPressed: () => ref.read(sessionProvider.notifier).logout(), child: const Text('Sign out'))]),
      body: ListView(children: [
        for (final m in ms)
          ListTile(
            key: Key('business-${m.businessId}'),
            leading: const Icon(Icons.store_outlined),
            title: Text(m.name),
            subtitle: Text(m.role),
            onTap: () => ref.read(sessionProvider.notifier).chooseBusiness(m),
          ),
      ]),
    );
  }
}
