import 'feature_flags.dart';

class StoreConfig {
  final String tenantId;
  final String? brandId;

  final String nameAr;
  final String nameEn;

  final String? logoUrl;
  final String? appIconUrl;

  final String primaryColor;
  final String secondaryColor;

  final String countryCode;
  final String currencyCode;
  final double vatRate;

  final FeatureFlags features;

  const StoreConfig({
    required this.tenantId,
    this.brandId,
    required this.nameAr,
    required this.nameEn,
    this.logoUrl,
    this.appIconUrl,
    this.primaryColor = '#FFFFFF',
    this.secondaryColor = '#000000',
    this.countryCode = 'SA',
    this.currencyCode = 'SAR',
    this.vatRate = 15,
    this.features = const FeatureFlags(),
  });

  factory StoreConfig.fromJson(
    Map<String, dynamic> json,
  ) {
    final rawFeatures =
        json['features'] as Map? ?? const {};

    return StoreConfig(
      tenantId: json['tenant_id']?.toString() ?? '',
      brandId: json['brand_id']?.toString(),
      nameAr: json['name_ar']?.toString() ?? '',
      nameEn: json['name_en']?.toString() ?? '',
      logoUrl: json['logo_url']?.toString(),
      appIconUrl: json['app_icon_url']?.toString(),
      primaryColor:
          json['primary_color']?.toString() ??
              '#FFFFFF',
      secondaryColor:
          json['secondary_color']?.toString() ??
              '#000000',
      countryCode:
          json['country_code']?.toString() ?? 'SA',
      currencyCode:
          json['currency_code']?.toString() ?? 'SAR',
      vatRate: double.tryParse(
            json['vat_rate']?.toString() ?? '',
          ) ??
          15,
      features: FeatureFlags(
        flags: rawFeatures.map(
          (key, value) => MapEntry(
            key.toString(),
            value == true ||
                value.toString() == '1',
          ),
        ),
      ),
    );
  }
}
