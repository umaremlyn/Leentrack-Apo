import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:leentrack/app_shell.dart';
import 'package:leentrack/core/breakpoints.dart';
import 'package:leentrack/core/nav_destinations.dart';

Future<void> pump(WidgetTester t, Size size, String role, {String selected = 'pos'}) async {
  t.view.physicalSize = size;
  t.view.devicePixelRatio = 1;
  addTearDown(t.view.reset);
  await t.pumpWidget(MaterialApp(
    home: AppShell(role: role, selectedId: selected, onSelect: (_) {}, body: const Text('BODY')),
  ));
}

void main() {
  test('breakpoints', () {
    expect(Breakpoints.of(360), FormFactor.mobile);
    expect(Breakpoints.of(768), FormFactor.tablet);
    expect(Breakpoints.of(1280), FormFactor.desktop);
  });

  test('cashier only sees POS, Sales, Customers (mirrors source sidebar)', () {
    expect(destinationsFor('cashier').map((d) => d.id), ['pos', 'sales', 'customers']);
    expect(destinationsFor('manager').map((d) => d.id), isNot(contains('staff')));
    expect(destinationsFor('owner').map((d) => d.id), contains('staff'));
  });

  testWidgets('mobile uses bottom navigation', (t) async {
    await pump(t, const Size(360, 800), 'owner');
    expect(find.byType(NavigationBar), findsOneWidget);
    expect(find.byType(NavigationRail), findsNothing);
  });

  testWidgets('tablet uses a navigation rail', (t) async {
    await pump(t, const Size(800, 1024), 'owner');
    expect(find.byType(NavigationRail), findsOneWidget);
    expect(find.byType(NavigationBar), findsNothing);
  });

  testWidgets('desktop uses a grouped sidebar', (t) async {
    await pump(t, const Size(1400, 900), 'owner');
    expect(find.byKey(const ValueKey('nav-pos')), findsOneWidget);
    expect(find.text('FINANCE'), findsOneWidget);
    expect(find.byType(NavigationBar), findsNothing);
  });

  testWidgets('cashier on desktop does not get manager entries', (t) async {
    await pump(t, const Size(1400, 900), 'cashier');
    expect(find.byKey(const ValueKey('nav-expenses')), findsNothing);
    expect(find.byKey(const ValueKey('nav-pos')), findsOneWidget);
  });
}
