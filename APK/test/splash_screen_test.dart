import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:sst_attendance/screens/splash_screen.dart';

/// Regression tests for the splash screen geometry.
///
/// Both cases here shipped as real bugs: the gradient covered only about 71% of
/// the screen width, and the company name ran off both edges. The cause was a
/// Container with a decoration and no explicit size, which shrink-wraps its child —
/// so the painted area was however wide the content happened to be, and content
/// wider than that simply overflowed. These tests pin the geometry so it cannot
/// regress silently, since the fault is invisible in code review.
void main() {
  /// The gradient layer, identified by having one — the logo tile is also a
  /// DecoratedBox, so type alone is not specific enough.
  Finder gradientBox() => find.byWidgetPredicate(
        (widget) =>
            widget is DecoratedBox &&
            widget.decoration is BoxDecoration &&
            (widget.decoration as BoxDecoration).gradient != null,
      );

  Future<Size> pumpSplash(WidgetTester tester, {required String companyName}) async {
    tester.view.physicalSize = const Size(1080, 2392);
    tester.view.devicePixelRatio = 2.75;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      MaterialApp(
        home: SplashScreen(
          companyName: companyName,
          message: 'Checking your account…',
        ),
      ),
    );
    await tester.pump();

    return tester.getSize(gradientBox().first);
  }

  testWidgets('gradient fills the full viewport width', (tester) async {
    final size = await pumpSplash(tester, companyName: 'SMART STEP TRANSPORTATION');

    final expectedWidth =
        tester.view.physicalSize.width / tester.view.devicePixelRatio;

    expect(
      size.width,
      expectedWidth,
      reason: 'The gradient must span the screen, not shrink-wrap its content.',
    );
    expect(tester.takeException(), isNull);
  });

  testWidgets('gradient fills the full viewport height', (tester) async {
    final size = await pumpSplash(tester, companyName: 'SMART STEP TRANSPORTATION');

    final expectedHeight =
        tester.view.physicalSize.height / tester.view.devicePixelRatio;

    expect(size.height, expectedHeight);
  });

  testWidgets('a very long company name wraps instead of overflowing',
      (tester) async {
    await pumpSplash(
      tester,
      companyName: 'SMART STEP TRANSPORTATION AND CONTRACTING '
          'GROUP OF COMPANIES INTERNATIONAL',
    );

    // A RenderFlex/paragraph overflow surfaces as an exception in tests, which is
    // exactly the failure the original layout produced on a real screen.
    expect(tester.takeException(), isNull);

    final screenWidth =
        tester.view.physicalSize.width / tester.view.devicePixelRatio;
    final nameSize = tester.getSize(
      find.text(
        'SMART STEP TRANSPORTATION AND CONTRACTING '
        'GROUP OF COMPANIES INTERNATIONAL',
      ),
    );

    expect(
      nameSize.width,
      lessThanOrEqualTo(screenWidth),
      reason: 'The name must stay inside the screen.',
    );
  });

  testWidgets('the logo tile keeps its fixed size despite the stretching column',
      (tester) async {
    await pumpSplash(tester, companyName: 'SST');

    // The column stretches its children across the cross axis, which would
    // otherwise override the tile's width and smear it across the screen.
    final logoTile = tester.getSize(
      find.ancestor(
        of: find.byIcon(Icons.location_on),
        matching: find.byType(Container),
      ).first,
    );

    expect(logoTile.width, 96);
    expect(logoTile.height, 96);
  });
}
