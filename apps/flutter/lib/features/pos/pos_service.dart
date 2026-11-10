import '../../core/money.dart';
import '../../data/local_db.dart';
import 'cart.dart';

class ProvisionalSale {
  const ProvisionalSale({required this.operationId, required this.total, required this.change, required this.lines, required this.createdAt});
  final String operationId;
  final int total;
  final int change;
  final List<({String name, int quantity, int unitPrice})> lines;
  final DateTime createdAt;
}

class CheckoutError implements Exception {
  const CheckoutError(this.message);
  final String message;
  @override
  String toString() => message;
}

/// Turns a cart into a queued `sale.checkout` operation (docs/14 §3.2). It sends WHAT happened — items, quantities, discount inputs, the amount
/// handed over, and the total the customer was quoted — never prices or totals as facts.
class PosService {
  PosService({required this.db, required this.newId, required this.clock});
  final LocalDb db;
  final String Function() newId;
  final DateTime Function() clock;

  ProvisionalSale checkout(
    Cart cart, {
    required String locationId,
    required bool credit,
    required String method,
    required int amount, // cash handed over (full) or paid now (credit)
    String? notes,
  }) {
    if (cart.isEmpty) throw const CheckoutError('The cart is empty.');
    if (credit && cart.customerId == null) throw const CheckoutError('Choose the customer who is buying on credit.');
    final d = cart.discounts;
    if (d.manualError.isNotEmpty) throw CheckoutError(d.manualError);
    final total = cart.total;
    if (credit) {
      if (amount < 0 || amount > total) throw const CheckoutError('The amount paid now cannot be more than the total.');
    } else if (amount < total) {
      throw CheckoutError('Amount received must be at least ${Cents.pretty(total)}.');
    }
    // Re-check stock at the moment of sale, against what this device has already promised.
    for (final l in cart.lines) {
      if (l.tracksStock && l.quantity > db.available(l.productId, locationId)) {
        throw CheckoutError('Only ${db.available(l.productId, locationId)} of ${l.name} available.');
      }
    }
    final id = newId();
    final now = clock();
    final payload = <String, dynamic>{
      'outlet_id': locationId,
      if (cart.customerId != null) 'customer_id': cart.customerId,
      'items': [for (final l in cart.lines) {'product_id': l.productId, 'quantity': l.quantity}],
      'discount': {
        if (cart.manualDiscount > 0) 'manual': Cents.format(cart.manualDiscount),
        'apply_group': cart.groupOn,
        if (d.pointsUsed > 0) 'points_redeemed': d.pointsUsed,
      },
      'payment': {'mode': credit ? 'credit' : 'full', 'method': method, 'amount': Cents.format(amount)},
      'expected_total': Cents.format(total),
      if (notes != null && notes.trim().isNotEmpty) 'notes': notes.trim(),
    };
    final lines = [for (final l in cart.lines) (name: l.name, quantity: l.quantity, unitPrice: l.unitPrice)];
    db.enqueue(
      id: id,
      type: 'sale.checkout',
      payload: payload,
      summary: '${cart.lines.length} item(s) · ${Cents.pretty(total)}',
      now: now,
      stock: [for (final l in cart.lines) if (l.tracksStock) (productId: l.productId, locationId: locationId, quantity: l.quantity)],
    );
    return ProvisionalSale(operationId: id, total: total, change: credit ? 0 : amount - total, lines: lines, createdAt: now);
  }

  /// A customer made at the till while offline. The id is chosen here and is what the server stores (docs/14: idempotent by id).
  String createCustomer({required String name, String? phone, String? email}) {
    final n = name.trim();
    if (n.isEmpty) throw const CheckoutError('Enter the customer name.');
    final id = newId();
    db.tx(() {
      db.insertLocalCustomer(id: id, name: n, phone: _blank(phone), email: _blank(email));
      db.enqueue(
        id: newId(),
        type: 'customer.create',
        payload: {'id': id, 'name': n, if (_blank(phone) != null) 'phone': _blank(phone), if (_blank(email) != null) 'email': _blank(email)},
        summary: 'New customer $n',
        now: clock(),
      );
    });
    return id;
  }

  static String? _blank(String? s) => (s == null || s.trim().isEmpty) ? null : s.trim();
}
