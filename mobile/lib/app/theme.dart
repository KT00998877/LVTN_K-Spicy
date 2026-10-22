import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

/// ============================================================
/// 🎨 MICAY DESIGN SYSTEM — Spicy Red + Warm Cream + Charcoal
/// ============================================================
/// Toàn bộ màu sắc / typography / radius / shadow phải lấy từ
/// file này. KHÔNG hard-code hex ở widget khác.
/// (Tham chiếu: restaurant-ui.mdc + DESIGN_SYSTEM.md)
/// ============================================================

class AppColors {
  AppColors._();

  // ── Brand colors ─────────────────────────────────────────
  /// Spicy Red — primary CTA, active nav, spicy indicator
  static const Color spicyRed = Color(0xFFD7263D);
  static const Color spicyRedDark = Color(0xFFA8122A);
  static const Color spicyRedSoft = Color(0xFFFCE7EA);

  /// Chili Orange — promotion, secondary action
  static const Color chiliOrange = Color(0xFFF46036);

  /// Golden Yellow — loyalty tier Vàng, accent
  static const Color goldenYellow = Color(0xFFF6BD60);

  /// Deep Charcoal — text emphasis, dark surfaces, navbar
  static const Color charcoal = Color(0xFF1A1A1A);
  static const Color charcoalSoft = Color(0xFF3D3D3D);

  /// Warm Cream — background mặc định (không dùng #FFFFFF thuần)
  static const Color cream = Color(0xFFFAF6EE);
  static const Color creamSoft = Color(0xFFF3ECDD);
  static const Color creamDark = Color(0xFFE8DFCB);

  /// Muted green — freshness indicator
  static const Color mutedGreen = Color(0xFF6B8E4E);

  // ── Semantic colors ──────────────────────────────────────
  static const Color success = Color(0xFF2E8B57);
  static const Color warning = Color(0xFFD97706);
  static const Color danger = Color(0xFFD7263D);
  static const Color info = Color(0xFF2563EB);

  // ── Neutral scale ────────────────────────────────────────
  static const Color textPrimary = charcoal;
  static const Color textSecondary = Color(0xFF6B6B6B);
  static const Color textMuted = Color(0xFF9A9A9A);
  static const Color textOnDark = cream;

  static const Color border = Color(0xFFE3DDD0);
  static const Color divider = Color(0xFFEEE6D5);

  static const Color overlay = Color(0x33000000); // 20% black
}

class AppRadius {
  AppRadius._();

  static const double xs = 6;
  static const double sm = 8;     // small controls
  static const double md = 12;    // normal components
  static const double lg = 16;    // large surfaces
  static const double xl = 20;    // hero sections
  static const double pill = 999; // chips/categories
}

class AppSpacing {
  AppSpacing._();

  static const double xxs = 4;
  static const double xs = 8;
  static const double sm = 12;
  static const double md = 16;
  static const double lg = 24;
  static const double xl = 32;
  static const double xxl = 48;
}

class AppShadow {
  AppShadow._();

  /// Subtle elevation cho card nổi nhẹ
  static const List<BoxShadow> card = [
    BoxShadow(
      color: Color(0x0F1A1A1A),
      blurRadius: 16,
      offset: Offset(0, 4),
    ),
  ];

  /// Dùng cho bottom sheet / modal / FAB
  static const List<BoxShadow> floating = [
    BoxShadow(
      color: Color(0x1A1A1A1A),
      blurRadius: 24,
      offset: Offset(0, 8),
    ),
  ];
}

class AppTypography {
  AppTypography._();

  /// Font chính: Be Vietnam Pro (hỗ trợ tiếng Việt tốt, hiện đại)
  /// Fallback: system font
  static TextTheme textTheme(TextTheme base) {
    final theme = GoogleFonts.beVietnamProTextTheme(base);
    return theme.copyWith(
      displayLarge: theme.displayLarge?.copyWith(
        fontWeight: FontWeight.w800,
        color: AppColors.textPrimary,
        height: 1.1,
      ),
      displayMedium: theme.displayMedium?.copyWith(
        fontWeight: FontWeight.w800,
        color: AppColors.textPrimary,
        height: 1.15,
      ),
      displaySmall: theme.displaySmall?.copyWith(
        fontWeight: FontWeight.w700,
        color: AppColors.textPrimary,
      ),
      headlineLarge: theme.headlineLarge?.copyWith(
        fontWeight: FontWeight.w700,
        color: AppColors.textPrimary,
      ),
      headlineMedium: theme.headlineMedium?.copyWith(
        fontWeight: FontWeight.w700,
        color: AppColors.textPrimary,
      ),
      headlineSmall: theme.headlineSmall?.copyWith(
        fontWeight: FontWeight.w700,
        color: AppColors.textPrimary,
      ),
      titleLarge: theme.titleLarge?.copyWith(
        fontWeight: FontWeight.w700,
        color: AppColors.textPrimary,
      ),
      titleMedium: theme.titleMedium?.copyWith(
        fontWeight: FontWeight.w600,
        color: AppColors.textPrimary,
      ),
      titleSmall: theme.titleSmall?.copyWith(
        fontWeight: FontWeight.w600,
        color: AppColors.textPrimary,
      ),
      bodyLarge: theme.bodyLarge?.copyWith(
        color: AppColors.textPrimary,
        height: 1.5,
      ),
      bodyMedium: theme.bodyMedium?.copyWith(
        color: AppColors.textSecondary,
        height: 1.5,
      ),
      bodySmall: theme.bodySmall?.copyWith(
        color: AppColors.textMuted,
        height: 1.4,
      ),
      labelLarge: theme.labelLarge?.copyWith(
        fontWeight: FontWeight.w600,
        color: AppColors.textPrimary,
      ),
    );
  }
}

class AppTheme {
  AppTheme._();

  static ThemeData light() {
    final colorScheme = ColorScheme.fromSeed(
      seedColor: AppColors.spicyRed,
      brightness: Brightness.light,
      primary: AppColors.spicyRed,
      onPrimary: AppColors.textOnDark,
      secondary: AppColors.chiliOrange,
      onSecondary: AppColors.textOnDark,
      surface: AppColors.cream,
      onSurface: AppColors.textPrimary,
      error: AppColors.danger,
    );

    final base = ThemeData(useMaterial3: true, colorScheme: colorScheme);
    final textTheme = AppTypography.textTheme(base.textTheme);

    return base.copyWith(
      scaffoldBackgroundColor: AppColors.cream,
      colorScheme: colorScheme,
      textTheme: textTheme,

      // ── AppBar ─────────────────────────────────────────
      appBarTheme: AppBarTheme(
        backgroundColor: AppColors.cream,
        foregroundColor: AppColors.textPrimary,
        elevation: 0,
        scrolledUnderElevation: 0,
        centerTitle: false,
        titleTextStyle: textTheme.titleLarge,
      ),

      // ── Bottom navigation ──────────────────────────────
      bottomNavigationBarTheme: const BottomNavigationBarThemeData(
        backgroundColor: AppColors.cream,
        selectedItemColor: AppColors.spicyRed,
        unselectedItemColor: AppColors.textMuted,
        type: BottomNavigationBarType.fixed,
        elevation: 0,
      ),

      // ── Card ───────────────────────────────────────────
      cardTheme: CardThemeData(
        color: Colors.white,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(AppRadius.lg),
        ),
      ),

      // ── Buttons ────────────────────────────────────────
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: AppColors.spicyRed,
          foregroundColor: AppColors.textOnDark,
          minimumSize: const Size.fromHeight(52), // touch target chuẩn
          padding: const EdgeInsets.symmetric(
            horizontal: AppSpacing.lg,
            vertical: AppSpacing.sm,
          ),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(AppRadius.md),
          ),
          textStyle: textTheme.labelLarge?.copyWith(fontSize: 16),
          elevation: 0,
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: AppColors.spicyRed,
          minimumSize: const Size.fromHeight(52),
          side: const BorderSide(color: AppColors.spicyRed, width: 1.5),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(AppRadius.md),
          ),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          foregroundColor: AppColors.spicyRed,
          textStyle: textTheme.labelLarge,
        ),
      ),

      // ── Input ──────────────────────────────────────────
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: Colors.white,
        contentPadding: const EdgeInsets.symmetric(
          horizontal: AppSpacing.md,
          vertical: AppSpacing.md,
        ),
        hintStyle: textTheme.bodyMedium?.copyWith(color: AppColors.textMuted),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(AppRadius.md),
          borderSide: const BorderSide(color: AppColors.border),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(AppRadius.md),
          borderSide: const BorderSide(color: AppColors.border),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(AppRadius.md),
          borderSide: const BorderSide(color: AppColors.spicyRed, width: 1.5),
        ),
        errorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(AppRadius.md),
          borderSide: const BorderSide(color: AppColors.danger),
        ),
        focusedErrorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(AppRadius.md),
          borderSide: const BorderSide(color: AppColors.danger, width: 1.5),
        ),
      ),

      // ── Divider ────────────────────────────────────────
      dividerTheme: const DividerThemeData(
        color: AppColors.divider,
        thickness: 1,
        space: 1,
      ),

      // ── Chip ───────────────────────────────────────────
      chipTheme: ChipThemeData(
        backgroundColor: AppColors.creamSoft,
        labelStyle: textTheme.labelLarge?.copyWith(fontSize: 13),
        side: BorderSide.none,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(AppRadius.pill),
        ),
        padding: const EdgeInsets.symmetric(
          horizontal: AppSpacing.sm,
          vertical: AppSpacing.xxs,
        ),
      ),
    );
  }
}
