import '../../core/money.dart';

/// What the till can compute offline. This is a PORT of the server's checkout maths (LoyaltyMath::checkoutDiscounts + tax) and is verified
/// against the same 2,268 golden vectors produced from the original TypeScript. It exists so the screen can show a total and so the sale can be
/// queued with `expected_total`; the SERVER still computes and records the real figures.
class CheckoutResult {
  const CheckoutResult({
    required this.groupPercentBp,
    required this.groupDiscount,
    required this.manual,
    required this.manualError,
    required this.maxPoints,
    required this.pointsUsed,
    required this.pointsValue,
    required this.discount,
  });
  final int groupPercentBp;
  final int groupDiscount;
  final int manual;
  final String manualError;
  final int maxPoints;
  final int pointsUsed;
  final int pointsValue;
  final int discount;
}

class CheckoutMath {
  const CheckoutMath._();

  static const int maxGroupBp = 9900;

  /// [groupPct] is a percentage as a number (7.5); out-of-range values are clamped like the server does (0…99).
  static CheckoutResult discounts({
    required int subtotal,
    required int manual,
    required double groupPct,
    required bool groupOn,
    required bool loyaltyOn,
    required int customerPoints,
    required int pointValue,
    required int pointsWanted,
  }) {
    final sub = subtotal < 0 ? 0 : subtotal;
    var bp = (groupPct * 100).round();
    if (bp < 0) bp = 0;
    if (bp > maxGroupBp) bp = maxGroupBp;
    final groupDiscount = (groupOn && bp != 0 && sub > 0) ? Cents.percent(sub, bp) : 0;

    final manualWanted = manual > 0 ? manual : 0;
    final tooMuch = manualWanted > 0 && sub > 0 && manualWanted + groupDiscount >= sub;
    final manualError = tooMuch ? (groupDiscount > 0 ? 'The discounts together must be less than the cart total.' : 'Discount must be less than the cart total.') : '';
    final man = manualError.isNotEmpty ? 0 : manualWanted;

    var room = sub - 1 - groupDiscount - man; // there must always be something left to pay
    if (room < 0) room = 0;
    final value = pointValue > 0 ? pointValue : 0;
    final maxPoints = (loyaltyOn && value > 0) ? _clamp(Cents.floorDiv(room, value), 0, customerPoints < 0 ? 0 : customerPoints) : 0;
    final pointsUsed = _clamp(pointsWanted, 0, maxPoints);
    final pointsValue = value * pointsUsed;
    return CheckoutResult(
      groupPercentBp: bp,
      groupDiscount: groupDiscount,
      manual: man,
      manualError: manualError,
      maxPoints: maxPoints,
      pointsUsed: pointsUsed,
      pointsValue: pointsValue,
      discount: groupDiscount + man + pointsValue,
    );
  }

  static int _clamp(int v, int lo, int hi) => v < lo ? lo : (v > hi ? hi : v);

  /// tax = (subtotal − discount) × rate, total = taxable + tax. [taxRateBp] in basis points of a percent (7.5 % = 750).
  static ({int taxable, int tax, int total}) total({required int subtotal, required int discount, required int taxRateBp}) {
    final taxable = subtotal - discount;
    final tax = Cents.percent(taxable < 0 ? 0 : taxable, taxRateBp);
    return (taxable: taxable, tax: tax, total: taxable + tax);
  }
}
