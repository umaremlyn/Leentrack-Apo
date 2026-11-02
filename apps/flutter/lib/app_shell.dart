import 'package:flutter/material.dart';

import 'core/breakpoints.dart';
import 'core/nav_destinations.dart';

/// Adaptive scaffold: bottom navigation (mobile), rail (tablet), grouped sidebar (desktop).
class AppShell extends StatelessWidget {
  const AppShell({super.key, required this.role, required this.selectedId, required this.onSelect, required this.body});

  final String role;
  final String selectedId;
  final ValueChanged<String> onSelect;
  final Widget body;

  @override
  Widget build(BuildContext context) {
    final items = destinationsFor(role);
    final ff = Breakpoints.ofContext(context);
    switch (ff) {
      case FormFactor.mobile:
        final primary = mobilePrimary(role);
        final idx = primary.indexWhere((d) => d.id == selectedId);
        return Scaffold(
          body: SafeArea(child: body),
          bottomNavigationBar: NavigationBar(
            selectedIndex: idx < 0 ? 0 : idx,
            onDestinationSelected: (i) => onSelect(primary[i].id),
            destinations: [for (final d in primary) NavigationDestination(icon: Icon(d.icon), label: d.label)],
          ),
        );
      case FormFactor.tablet:
        final idx = items.indexWhere((d) => d.id == selectedId);
        return Scaffold(
          body: Row(children: [
            NavigationRail(
              selectedIndex: idx < 0 ? 0 : idx,
              labelType: NavigationRailLabelType.all,
              onDestinationSelected: (i) => onSelect(items[i].id),
              destinations: [for (final d in items) NavigationRailDestination(icon: Icon(d.icon), label: Text(d.label))],
            ),
            const VerticalDivider(width: 1),
            Expanded(child: SafeArea(child: body)),
          ]),
        );
      case FormFactor.desktop:
        return Scaffold(
          body: Row(children: [
            SizedBox(
              width: 248,
              child: ListView(padding: const EdgeInsets.symmetric(vertical: 12), children: _sidebar(context, items)),
            ),
            const VerticalDivider(width: 1),
            Expanded(child: body),
          ]),
        );
    }
  }

  List<Widget> _sidebar(BuildContext context, List<NavDestination> items) {
    final out = <Widget>[];
    String? group;
    for (final d in items) {
      if (d.group != group) {
        group = d.group;
        out.add(Padding(
          padding: const EdgeInsets.fromLTRB(20, 16, 20, 4),
          child: Text(group, style: Theme.of(context).textTheme.labelSmall),
        ));
      }
      out.add(ListTile(
        key: ValueKey('nav-${d.id}'),
        dense: true,
        leading: Icon(d.icon),
        title: Text(d.label),
        selected: d.id == selectedId,
        onTap: () => onSelect(d.id),
      ));
    }
    return out;
  }
}
