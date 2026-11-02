import 'package:flutter/material.dart';

/// Primary navigation, mirroring the web sidebar groups (MAIN/STOCK/SALES/FINANCE/TEAM).
/// `roles` follows the source's sidebar; real enforcement is server-side (docs/07).
class NavDestination {
  const NavDestination(this.id, this.label, this.icon, this.group, this.roles);
  final String id;
  final String label;
  final IconData icon;
  final String group;
  final Set<String> roles;
}

const _all = {'owner', 'manager', 'cashier'};
const _mgr = {'owner', 'manager'};

const List<NavDestination> allDestinations = [
  NavDestination('dashboard', 'Dashboard', Icons.dashboard_outlined, 'MAIN', _mgr),
  NavDestination('pos', 'POS', Icons.storefront_outlined, 'MAIN', _all),
  NavDestination('inventory', 'Inventory', Icons.inventory_2_outlined, 'STOCK', _mgr),
  NavDestination('purchases', 'Purchases', Icons.shopping_bag_outlined, 'STOCK', _mgr),
  NavDestination('sales', 'Sales', Icons.receipt_long_outlined, 'SALES', _all),
  NavDestination('credit', 'Credit Sales', Icons.menu_book_outlined, 'SALES', _mgr),
  NavDestination('customers', 'Customers', Icons.people_outline, 'SALES', _all),
  NavDestination('profit', 'My Profit', Icons.paid_outlined, 'FINANCE', _mgr),
  NavDestination('expenses', 'Expenses', Icons.trending_down, 'FINANCE', _mgr),
  NavDestination('reports', 'Reports', Icons.bar_chart_outlined, 'FINANCE', _mgr),
  NavDestination('staff', 'Staff', Icons.manage_accounts_outlined, 'TEAM', {'owner'}),
  NavDestination('locations', 'Locations', Icons.place_outlined, 'TEAM', _mgr),
];

List<NavDestination> destinationsFor(String role) =>
    allDestinations.where((d) => d.roles.contains(role)).toList(growable: false);

/// Mobile bottom bar shows at most 4 primary items + "More".
List<NavDestination> mobilePrimary(String role) => destinationsFor(role).take(4).toList(growable: false);
