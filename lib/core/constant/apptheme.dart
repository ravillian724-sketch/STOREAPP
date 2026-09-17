import 'package:flutter/material.dart';

import '../platform/store_config.dart';
import 'color.dart';

class AppTheme {
  static ThemeData build({
    required bool isArabic,
    StoreConfig? store,
  }) {
    final primary = _hexColor(store?.primaryColor) ?? AppColor.primaryColor;
    final secondary = _hexColor(store?.secondaryColor) ?? AppColor.secondColor;
    final scheme = ColorScheme.fromSeed(
      seedColor: primary,
      brightness: Brightness.light,
    ).copyWith(
      primary: primary,
      secondary: secondary,
      surface: const Color(0xFFF8FAFC),
      error: AppColor.redDark,
    );

    final textTheme = const TextTheme(
      headlineLarge:
          TextStyle(fontSize: 28, fontWeight: FontWeight.w700, height: 1.25),
      headlineMedium:
          TextStyle(fontSize: 24, fontWeight: FontWeight.w700, height: 1.3),
      titleLarge:
          TextStyle(fontSize: 20, fontWeight: FontWeight.w700, height: 1.3),
      titleMedium:
          TextStyle(fontSize: 17, fontWeight: FontWeight.w600, height: 1.35),
      bodyLarge:
          TextStyle(fontSize: 16, fontWeight: FontWeight.w400, height: 1.5),
      bodyMedium:
          TextStyle(fontSize: 14, fontWeight: FontWeight.w400, height: 1.5),
      labelLarge:
          TextStyle(fontSize: 15, fontWeight: FontWeight.w600, height: 1.2),
    ).apply(
      bodyColor: AppColor.darkGrey,
      displayColor: AppColor.fourthColor,
    );

    return ThemeData(
      useMaterial3: true,
      fontFamily: 'Cairo',
      colorScheme: scheme,
      scaffoldBackgroundColor: AppColor.backgroundCoor,
      textTheme: textTheme,
      appBarTheme: AppBarTheme(
        backgroundColor: AppColor.backgroundCoor,
        foregroundColor: AppColor.fourthColor,
        centerTitle: true,
        elevation: 0,
        scrolledUnderElevation: 0.5,
        titleTextStyle:
            textTheme.titleLarge?.copyWith(color: AppColor.fourthColor),
      ),
      cardTheme: CardThemeData(
        color: Colors.white,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(18),
          side: const BorderSide(color: Color(0xFFE7ECF1)),
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: Colors.white,
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(16),
          borderSide: const BorderSide(color: Color(0xFFDCE3EA)),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(16),
          borderSide: const BorderSide(color: Color(0xFFDCE3EA)),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(16),
          borderSide: BorderSide(color: primary, width: 1.6),
        ),
        contentPadding:
            const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: primary,
          foregroundColor: Colors.white,
          minimumSize: const Size(48, 50),
          elevation: 0,
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          textStyle: textTheme.labelLarge,
        ),
      ),
      floatingActionButtonTheme: FloatingActionButtonThemeData(
        backgroundColor: primary,
        foregroundColor: Colors.white,
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: Colors.white,
        indicatorColor: primary.withValues(alpha: 0.12),
        labelTextStyle: WidgetStatePropertyAll(textTheme.labelLarge),
      ),
      dividerTheme:
          const DividerThemeData(color: Color(0xFFE7ECF1), thickness: 1),
    );
  }

  static Color? _hexColor(String? value) {
    if (value == null) return null;
    var hex = value.trim().replaceFirst('#', '');
    if (hex.length == 6) hex = 'FF$hex';
    if (hex.length != 8) return null;
    final parsed = int.tryParse(hex, radix: 16);
    return parsed == null ? null : Color(parsed);
  }
}

ThemeData themeEnglish = AppTheme.build(isArabic: false);
ThemeData themeArabic = AppTheme.build(isArabic: true);
