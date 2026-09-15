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

  static const String apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://192.168.130.157/ecommerce',
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

  static bool get isProduction =>
      environment == AppEnvironment.production;
}
