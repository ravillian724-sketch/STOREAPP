import 'package:ecommerce_app/core/functions/storefront_display.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('keeps absolute product image urls and rejects empty values', () {
    expect(resolveProductImageUrl(null), isNull);
    expect(resolveProductImageUrl('   '), isNull);
    expect(
      resolveProductImageUrl('https://cdn.example.test/p/1.webp'),
      'https://cdn.example.test/p/1.webp',
    );
  });

  test('formats SAR without a dollar sign', () {
    expect(
      formatStoreMoney(25.75, 'SAR', isArabic: false),
      '25.75 SAR',
    );
    expect(
      formatStoreMoney(25.75, 'SAR', isArabic: true),
      '25.75 ر.س',
    );
    expect(
      formatStoreMoney(10, 'AED', isArabic: true),
      '10.00 AED',
    );
  });

  test('formats minor-unit money without floating-point conversion', () {
    expect(
      formatStoreMinorMoney(2575, 'SAR', isArabic: false),
      '25.75 SAR',
    );
    expect(
      formatStoreMinorMoney(5, 'SAR', isArabic: true),
      '0.05 ر.س',
    );
    expect(
      formatStoreMinorMoney(-123, 'AED', isArabic: false),
      '-1.23 AED',
    );
  });
}
