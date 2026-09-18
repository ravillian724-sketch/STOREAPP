import 'environment_config.dart';

class StartupPerformancePolicy {
  StartupPerformancePolicy._();

  static const Duration productionBootstrapNetworkTimeout =
      Duration(seconds: 5);
  static const Duration productionCriticalPreparationTimeout =
      Duration(seconds: 3);

  static const Duration developmentBootstrapNetworkTimeout =
      Duration(seconds: 30);
  static const Duration developmentCriticalPreparationTimeout =
      Duration(seconds: 10);

  static Duration bootstrapNetworkTimeoutFor(AppEnvironment environment) {
    return environment == AppEnvironment.development
        ? developmentBootstrapNetworkTimeout
        : productionBootstrapNetworkTimeout;
  }

  static Duration criticalPreparationTimeoutFor(AppEnvironment environment) {
    return environment == AppEnvironment.development
        ? developmentCriticalPreparationTimeout
        : productionCriticalPreparationTimeout;
  }

  static Duration get bootstrapNetworkTimeout =>
      bootstrapNetworkTimeoutFor(EnvironmentConfig.environment);

  static Duration get criticalPreparationTimeout =>
      criticalPreparationTimeoutFor(EnvironmentConfig.environment);
}
