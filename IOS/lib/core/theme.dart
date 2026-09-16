import 'package:flutter/material.dart';

/// Visual identity, matching the admin panel so the two read as one product.
class AppTheme {
  static const Color brand = Color(0xFF1F4FD8);
  static const Color ink = Color(0xFF16233A);
  static const Color inkSoft = Color(0xFF5D6B83);
  static const Color ok = Color(0xFF12855C);
  static const Color warn = Color(0xFFA86400);
  static const Color bad = Color(0xFFC22F38);
  static const Color surface = Color(0xFFF4F6FA);

  static ThemeData build() {
    final scheme = ColorScheme.fromSeed(
      seedColor: brand,
      primary: brand,
      brightness: Brightness.light,
    );

    return ThemeData(
      useMaterial3: true,
      colorScheme: scheme,
      scaffoldBackgroundColor: surface,
      appBarTheme: const AppBarTheme(
        backgroundColor: Colors.white,
        foregroundColor: ink,
        elevation: 0,
        centerTitle: false,
        surfaceTintColor: Colors.transparent,
      ),
      // Card styling is deliberately left to the Material 3 default rather than set
      // through a theme: the class backing ThemeData.cardTheme was renamed between
      // Flutter versions, and pinning it here would make the app fail to compile on
      // one side or the other of that change. Individual cards override colour and
      // shape where the design needs it.
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: Colors.white,
        contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(10),
          borderSide: const BorderSide(color: Color(0xFFCFD7E5)),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(10),
          borderSide: const BorderSide(color: Color(0xFFCFD7E5)),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(10),
          borderSide: const BorderSide(color: brand, width: 2),
        ),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: const Size.fromHeight(52),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          textStyle: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          minimumSize: const Size.fromHeight(50),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        ),
      ),
      snackBarTheme: const SnackBarThemeData(behavior: SnackBarBehavior.floating),
    );
  }
}

/// Small status pill, the same vocabulary as the panel's badges.
class StatusChip extends StatelessWidget {
  const StatusChip({
    super.key,
    required this.label,
    required this.tone,
    this.icon,
  });

  final String label;
  final ChipTone tone;
  final IconData? icon;

  @override
  Widget build(BuildContext context) {
    final colours = switch (tone) {
      ChipTone.ok => (const Color(0xFFE4F6EE), AppTheme.ok),
      ChipTone.warn => (const Color(0xFFFDF2DC), AppTheme.warn),
      ChipTone.bad => (const Color(0xFFFDEAEC), AppTheme.bad),
      ChipTone.info => (const Color(0xFFE8EEFF), AppTheme.brand),
      ChipTone.muted => (const Color(0xFFEEF1F6), AppTheme.inkSoft),
    };

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: colours.$1,
        borderRadius: BorderRadius.circular(999),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (icon != null) ...[
            Icon(icon, size: 14, color: colours.$2),
            const SizedBox(width: 5),
          ],
          Text(
            label,
            style: TextStyle(
              color: colours.$2,
              fontSize: 12.5,
              fontWeight: FontWeight.w600,
            ),
          ),
        ],
      ),
    );
  }
}

enum ChipTone { ok, warn, bad, info, muted }
