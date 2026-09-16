import 'dart:io';

import 'package:flutter/material.dart';

import '../core/theme.dart';

/// Branded loading screen, shown while the app checks the session with the server.
///
/// It exists to continue the native splash rather than duplicate it: the system
/// splash is drawn before any app code runs, so it can only ever show a bundled
/// asset. This one runs in Dart, so it can show the company's own logo — and it
/// covers the network round trip that the native splash cannot wait for.
class SplashScreen extends StatelessWidget {
  const SplashScreen({
    super.key,
    this.logoUrl,
    this.logoFile,
    this.companyName,
    this.message,
  });

  final String? logoUrl;

  /// Cached copy on disk. Preferred over [logoUrl] so the logo is on screen in the
  /// first frame instead of appearing part-way through the splash.
  final File? logoFile;

  final String? companyName;
  final String? message;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      // SizedBox.expand, not a bare Container: a Container that has a decoration
      // and no explicit size shrink-wraps its child, so the gradient only covered
      // the width its content happened to need and left the rest of the screen on
      // the scaffold background. Forcing the full viewport fixes that at the root.
      body: SizedBox.expand(
        child: DecoratedBox(
          decoration: const BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
              colors: [AppTheme.brand, Color(0xFF163BA6)],
            ),
          ),
          child: SafeArea(
            child: Padding(
              // Horizontal room so a long company name wraps inside the screen
              // instead of running off both edges.
              padding: const EdgeInsets.symmetric(horizontal: 28),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const Spacer(),
                  // Centred explicitly: the Column stretches its children across
                  // the cross axis, which would otherwise override the logo tile's
                  // fixed 96px and smear it across the whole width.
                  Center(child: _logo()),
                  const SizedBox(height: 22),
                  Text(
                    companyName?.isNotEmpty == true ? companyName! : 'SST Attendance',
                    textAlign: TextAlign.center,
                    // Two lines is enough for the longest realistic company name,
                    // and the ellipsis keeps anything longer from breaking layout.
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 19,
                      fontWeight: FontWeight.w700,
                      height: 1.25,
                      letterSpacing: -0.2,
                    ),
                  ),
                  const SizedBox(height: 6),
                  Text(
                    'Attendance',
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      color: Colors.white.withValues(alpha: 0.7),
                      fontSize: 13,
                      letterSpacing: 1.5,
                    ),
                  ),
                  const Spacer(),
                  Center(
                    child: SizedBox(
                      width: 26,
                      height: 26,
                      child: CircularProgressIndicator(
                        strokeWidth: 2.4,
                        valueColor: AlwaysStoppedAnimation(
                          Colors.white.withValues(alpha: 0.85),
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(height: 14),
                  Text(
                    message ?? 'Loading…',
                    textAlign: TextAlign.center,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      color: Colors.white.withValues(alpha: 0.75),
                      fontSize: 12.5,
                    ),
                  ),
                  const SizedBox(height: 34),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _logo() {
    return Container(
      width: 96,
      height: 96,
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(24),
        boxShadow: const [
          BoxShadow(color: Color(0x33000000), blurRadius: 24, offset: Offset(0, 8)),
        ],
      ),
      clipBehavior: Clip.antiAlias,
      child: Padding(
        padding: const EdgeInsets.all(10),
        child: _image(),
      ),
    );
  }

  /// Disk first, then network, then the built-in mark.
  ///
  /// No loadingBuilder placeholder on the network path: a spinner inside the logo
  /// tile while another spinner turns below it just looks broken.
  Widget _image() {
    final file = logoFile;
    if (file != null) {
      return Image.file(
        file,
        fit: BoxFit.contain,
        errorBuilder: (_, __, ___) =>
            logoUrl != null ? _networkImage() : _fallback(),
      );
    }
    return logoUrl != null ? _networkImage() : _fallback();
  }

  Widget _networkImage() => Image.network(
        logoUrl!,
        fit: BoxFit.contain,
        errorBuilder: (_, __, ___) => _fallback(),
      );

  Widget _fallback() => const Center(
        child: Icon(Icons.location_on, size: 52, color: AppTheme.brand),
      );
}
