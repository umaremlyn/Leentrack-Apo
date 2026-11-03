import '../../core/money.dart';
import 'checkout_math.dart';

class CartLine {
  CartLine({required this.productId, required this.name, required this.unitPrice, required this.quantity, required this.tracksStock});
  final String productId;
  final String name;
  final int unitPrice; // minor units, as last downloaded
  int quantity;
  final bool tracksStock;
  int get lineTotal => unitPrice * quantity;
}

class CartSettings {
  const CartSettings({this.taxRateBp = 0, this.loyaltyEnabled = false, this.pointValue = 0, this.currency = 'NGN'});
  final int taxRateBp;
  final bool loyaltyEnabled;
  final int pointValue; // minor units per point
  final String currency;

  /// From the `settings` row the pull stored (docs/14 §4). Missing or odd values mean "no tax / no loyalty", never a guess.
  factory CartSettings.fromSettings(Map<String, dynamic> s) {
    int bp = 0;
    try {
      final t = s['tax_rate'];
      if (t != null) bp = Cents.basisPoints('$t');
    } catch (_) {}
    final l = s['loyalty'] is Map ? Map<String, dynamic>.from(s['loyalty'] as Map) : <String, dynamic>{};
    int pv = 0;
    try {
      if (l['point_value'] != null) pv = Cents.parse('${l['point_value']}');
    } catch (_) {}
    return CartSettings(taxRateBp: bp, loyaltyEnabled: l['enabled'] == true, pointValue: pv, currency: (s['currency'] as String?) ?? 'NGN');
  }
}

/// The shopping cart: pure state and arithmetic, no I/O. The figures it shows are PROVISIONAL (see CheckoutMath); the server records the real ones.
class Cart {
  Cart(this.settings);
  final CartSettings settings;
  final Map<String, CartLine> _lines = {};
  String? customerId;
  int customerPoints = 0;
  double groupPercent = 0;
  bool groupOn = true;
  int manualDiscount = 0;
  int pointsWanted = 0;

  List<CartLine> get lines => _lines.values.toList();
  bool get isEmpty => _lines.isEmpty;
  int quantityOf(String productId) => _lines[productId]?.quantity ?? 0;
  int get subtotal => _lines.values.fold(0, (a, l) => a + l.lineTotal);

  CheckoutResult get discounts => CheckoutMath.discounts(
        subtotal: subtotal,
        manual: manualDiscount,
        groupPct: groupPercent,
        groupOn: groupOn && customerId != null,
        loyaltyOn: settings.loyaltyEnabled && customerId != null,
        customerPoints: customerPoints,
        pointValue: settings.pointValue,
        pointsWanted: pointsWanted,
      );

  ({int taxable, int tax, int total}) get totals => CheckoutMath.total(subtotal: subtotal, discount: discounts.discount, taxRateBp: settings.taxRateBp);
  int get total => totals.total;

  /// Returns a message when it cannot be added (not enough stock), otherwise null. [available] is the stock this device can still promise.
  String? add({required String productId, required String name, required int unitPrice, required bool tracksStock, required int available, int quantity = 1}) {
    if (quantity < 1) return 'Quantity must be at least 1.';
    final have = quantityOf(productId);
    if (tracksStock && have + quantity > available) {
      return available <= 0 ? '$name is out of stock.' : 'Only $available of $name available.';
    }
    final line = _lines[productId];
    if (line == null) {
      _lines[productId] = CartLine(productId: productId, name: name, unitPrice: unitPrice, quantity: quantity, tracksStock: tracksStock);
    } else {
      line.quantity += quantity;
    }
    return null;
  }

  String? setQuantity(String productId, int quantity, {required int available}) {
    final line = _lines[productId];
    if (line == null) return null;
    if (quantity <= 0) {
      _lines.remove(productId);
      return null;
    }
    if (line.tracksStock && quantity > available) return 'Only $available of ${line.name} available.';
    line.quantity = quantity;
    return null;
  }

  void remove(String productId) => _lines.remove(productId);

  void clear() {
    _lines.clear();
    customerId = null;
    customerPoints = 0;
    groupPercent = 0;
    manualDiscount = 0;
    pointsWanted = 0;
    groupOn = true;
  }
}
