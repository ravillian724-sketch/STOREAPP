enum AppEnvironment {
  development,
  staging,
  production,
}

class EnvironmentConfig {
  EnvironmentConfig._();

  static const String environmentName = String.fromEnvironment(
    'APP_ENV',
    defaultValue: 'development',
  );

  /// API origin is intentionally not given a network fallback.
  /// Every deployable build must provide API_BASE_URL explicitly.
  static const String apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: '',
  );

  static AppEnvironment get environment {
    switch (environmentName.toLowerCase()) {
      case 'production':
        return AppEnvironment.production;
      case 'staging':
        return AppEnvironment.staging;
      default:
        return AppEnvironment.development;
    }
  }

  static bool get isProduction => environment == AppEnvironment.production;

  static Uri requireApiBaseUri([String? override]) {
    final raw = (override ?? apiBaseUrl).trim();
    if (raw.isEmpty) {
      throw StateError('API_BASE_URL is not configured.');
    }

    final uri = Uri.tryParse(raw);
    if (uri == null || !uri.hasScheme || uri.host.isEmpty) {
      throw StateError('API_BASE_URL must be an absolute URL.');
    }

    if (isProduction && uri.scheme.toLowerCase() != 'https') {
      throw StateError('Production API_BASE_URL must use HTTPS.');
    }

    if (uri.scheme != 'http' && uri.scheme != 'https') {
      throw StateError('API_BASE_URL must use HTTP or HTTPS.');
    }

    return uri.replace(path: uri.path.replaceFirst(RegExp(r'/+$'), ''));
  }
}
