import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:leentrack/core/money.dart';
import 'package:leentrack/features/pos/checkout_math.dart';

int c(num v) => (v * 100).round();

void main() {
  final file = File('../../backend/tests/Golden/loyalty_checkout.json');

  test('the till computes discounts exactly like the original TypeScript (2,268 golden vectors)', () {
    final g = jsonDecode(file.readAsStringSync()) as Map<String, dynamic>;
    final cases = (g['cases'] as List).cast<Map<String, dynamic>>();
    expect(cases.length, 2268);
    for (final k in cases) {
      final i = k['input'] as Map<String, dynamic>;
      final o = k['output'] as Map<String, dynamic>;
      final r = CheckoutMath.discounts(
        subtotal: c(i['subtotal'] as num),
        manual: c(i['manual'] as num),
        groupPct: (i['groupPct'] as num).toDouble(),
        groupOn: i['groupOn'] as bool,
        loyaltyOn: i['loyaltyOn'] as bool,
        customerPoints: (i['customerPoints'] as num).toInt(),
        pointValue: c(i['pointValue'] as num),
        pointsWanted: (i['pointsWanted'] as num).toInt(),
      );
      final why = jsonEncode(k);
      expect(r.groupDiscount, c(o['groupDiscount'] as num), reason: 'groupDiscount $why');
      expect(r.manual, c(o['manual'] as num), reason: 'manual $why');
      expect(r.manualError, o['manualError'], reason: 'manualError $why');
      expect(r.maxPoints, (o['maxPoints'] as num).toInt(), reason: 'maxPoints $why');
      expect(r.pointsUsed, (o['pointsUsed'] as num).toInt(), reason: 'pointsUsed $why');
      expect(r.pointsValue, c(o['pointsValue'] as num), reason: 'pointsValue $why');
      expect(r.discount, c(o['discount'] as num), reason: 'discount $why');
      expect(r.groupPercentBp, (( (o['groupPercent'] as num) * 100)).round(), reason: 'groupPercent $why');
    }
  });

  test('tax and total round half-up like the server', () {
    // 14,500.50 − 500.00 = 14,000.50; 7.5 % = 1,050.0375 -> 1,050.04
    final t = CheckoutMath.total(subtotal: 1450050, discount: 50000, taxRateBp: 750);
    expect([t.taxable, t.tax, t.total], [1400050, 105004, 1505054]);
    expect(CheckoutMath.total(subtotal: 1000, discount: 0, taxRateBp: 0).total, 1000);
    expect(CheckoutMath.total(subtotal: 5, discount: 0, taxRateBp: 5000).tax, 3, reason: '0.05 × 50 % = 0.025 -> 0.03');
  });

  test('money parsing and formatting', () {
    expect(Cents.parse('1500.50'), 150050);
    expect(Cents.parse('12'), 1200);
    expect(Cents.parse('0.5'), 50);
    expect(Cents.format(5), '0.05');
    expect(Cents.format(-12345), '-123.45');
    expect(Cents.pretty(123456789), '1,234,567.89');
    expect(Cents.basisPoints('7.5'), 750);
    expect(Cents.basisPoints('abc'), 0);
    for (final bad in ['1.234', 'x', '', '1,5', '1e3']) {
      expect(() => Cents.parse(bad), throwsFormatException, reason: bad);
    }
    expect(Cents.floorDiv(-7, 2), -4);
    expect(Cents.floorDiv(7, 2), 3);
  });
}
