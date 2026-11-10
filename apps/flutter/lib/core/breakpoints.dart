import 'package:flutter/widgets.dart';

/// One place that decides which layout family is used (spec: mobile / tablet / desktop must not be a stretched phone UI).
enum FormFactor { mobile, tablet, desktop }

class Breakpoints {
  const Breakpoints._();
  static const double tablet = 600;
  static const double desktop = 1024;

  static FormFactor of(double width) {
    if (width >= desktop) return FormFactor.desktop;
    if (width >= tablet) return FormFactor.tablet;
    return FormFactor.mobile;
  }

  static FormFactor ofContext(BuildContext context) => of(MediaQuery.sizeOf(context).width);
}
