/// Money is ALWAYS whole minor units (kobo, cents …) held in an `int`: no floats anywhere near a price.
/// Mirrors the server's `Money` class (backend/app/Core/Money.php) so a till can show exactly what the server will record.
class Cents {
  const Cents._();

  /// "1500.50" / "12" / "0.5" → minor units. Accepts at most 2 decimals; anything else throws [FormatException].
  static int parse(String s) {
    final m = RegExp(r'^(-?)(\d+)(?:\.(\d{1,2}))?$').firstMatch(s.trim());
    if (m == null) throw FormatException('Not an amount: "$s"');
    final whole = int.parse(m.group(2)!);
    final frac = int.parse((m.group(3) ?? '').padRight(2, '0'));
    final v = whole * 100 + frac;
    return m.group(1) == '-' ? -v : v;
  }

  /// minor units → "1500.50" (no currency symbol, no grouping: that is a display concern).
  static String format(int minor) {
    final a = minor.abs();
    return '${minor < 0 ? '-' : ''}${a ~/ 100}.${(a % 100).toString().padLeft(2, '0')}';
  }

  /// With thousands separators for screens: 1,500.50
  static String pretty(int minor) {
    final f = format(minor);
    final neg = f.startsWith('-');
    final parts = (neg ? f.substring(1) : f).split('.');
    final w = parts[0].replaceAllMapped(RegExp(r'\B(?=(\d{3})+(?!\d))'), (_) => ',');
    return '${neg ? '-' : ''}$w.${parts[1]}';
  }

  /// `minor × numerator / denominator`, rounded half-up (non-negative inputs), exactly like the server.
  static int mulRatio(int minor, int numerator, int denominator) => (minor * numerator + denominator ~/ 2) ~/ denominator;

  /// A percentage given in basis points of a percent (7.5 % = 750).
  static int percent(int minor, int basisPoints) => mulRatio(minor, basisPoints, 10000);

  /// floor(a / b) for b > 0, correct for negative a.
  static int floorDiv(int a, int b) {
    final q = a ~/ b;
    return (a % b != 0 && a < 0) ? q - 1 : q;
  }

  /// "7.5" → 750 basis points; invalid → 0.
  static int basisPoints(String pct) {
    final m = RegExp(r'^(\d{1,3})(?:\.(\d{1,2}))?$').firstMatch(pct.trim());
    if (m == null) return 0;
    return int.parse(m.group(1)!) * 100 + int.parse((m.group(2) ?? '').padRight(2, '0'));
  }
}
