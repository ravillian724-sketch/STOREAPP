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
  final label = normalizedCurrency == 'SAR'
      ? (isArabic ? 'ر.س' : 'SAR')
      : normalizedCurrency;

  return '${(amount ?? 0).toStringAsFixed(2)} $label';
}
