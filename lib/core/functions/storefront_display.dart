import 'package:ecommerce_app/linkapi.dart';

String? resolveProductImageUrl(String? value) {
  final normalized = value?.trim() ?? '';

  if (normalized.isEmpty) {
    return null;
  }

  final uri = Uri.tryParse(normalized);

  if (uri != null &&
      (uri.scheme.toLowerCase() == 'http' ||
          uri.scheme.toLowerCase() == 'https')) {
    return normalized;
  }

  return '${AppLink.imageItems}/$normalized';
}

String formatStoreMoney(
  double? amount,
  String? currencyCode, {
  required bool isArabic,
}) {
  final normalizedCurrency = (currencyCode ?? 'SAR').trim().toUpperCase();
  final label = _currencyLabel(
    normalizedCurrency,
    isArabic: isArabic,
  );

  return '${(amount ?? 0).toStringAsFixed(2)} $label';
}

String formatStoreMinorMoney(
  int amountMinor,
  String? currencyCode, {
  required bool isArabic,
}) {
  final normalizedCurrency = (currencyCode ?? 'SAR').trim().toUpperCase();
  final label = _currencyLabel(
    normalizedCurrency,
    isArabic: isArabic,
  );

  final negative = amountMinor < 0;
  final absolute = amountMinor.abs();
  final whole = absolute ~/ 100;
  final fraction = (absolute % 100).toString().padLeft(2, '0');
  final sign = negative ? '-' : '';

  return '$sign$whole.$fraction $label';
}

String _currencyLabel(
  String currencyCode, {
  required bool isArabic,
}) {
  return currencyCode == 'SAR' ? (isArabic ? 'ر.س' : 'SAR') : currencyCode;
}
