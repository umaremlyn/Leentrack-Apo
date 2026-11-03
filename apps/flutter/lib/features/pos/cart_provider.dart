import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';
import 'cart.dart';

typedef CartView = ({Cart cart, int rev});

/// The cart is plain mutable state (cart.dart); this wrapper tells the UI when it changed.
class CartController extends Notifier<CartView> {
  @override
  CartView build() {
    final w = ref.watch(sessionProvider.select((s) => s.work));
    final settings = w == null ? const CartSettings() : CartSettings.fromSettings(w.db.metaJson('settings'));
    return (cart: Cart(settings), rev: 0);
  }

  Cart get cart => state.cart;

  void _touch() => state = (cart: state.cart, rev: state.rev + 1);

  /// Returns an error message or null.
  String? addProduct(Map<String, Object?> p, String locationId) {
    final w = ref.read(sessionProvider).work!;
    final err = state.cart.add(
      productId: p['id'] as String,
      name: p['name'] as String,
      unitPrice: p['selling_price'] as int,
      tracksStock: p['item_type'] == 'product',
      available: w.db.available(p['id'] as String, locationId),
    );
    _touch();
    return err;
  }

  String? setQuantity(String productId, int q, String locationId) {
    final w = ref.read(sessionProvider).work!;
    final err = state.cart.setQuantity(productId, q, available: w.db.available(productId, locationId));
    _touch();
    return err;
  }

  void setCustomer(Map<String, Object?>? c) {
    final cart = state.cart;
    cart.customerId = c?['id'] as String?;
    cart.groupPercent = c == null ? 0 : ((c['discount_bp'] as int?) ?? 0) / 100;
    cart.customerPoints = c == null ? 0 : ((c['loyalty_points'] as int?) ?? 0);
    cart.pointsWanted = 0;
    _touch();
  }

  void setManualDiscount(int minor) {
    state.cart.manualDiscount = minor;
    _touch();
  }

  void clear() {
    state.cart.clear();
    _touch();
  }
}

final cartProvider = NotifierProvider<CartController, CartView>(CartController.new);
