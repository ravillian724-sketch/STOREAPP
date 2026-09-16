class AppInstanceConfig {
  AppInstanceConfig._();

  static const String instanceKey =
      String.fromEnvironment(
    'APP_INSTANCE_KEY',
    defaultValue: '',
  );

  static bool get isConfigured =>
      instanceKey.trim().isNotEmpty;

  static void validate() {
    if (!isConfigured) {
      throw StateError(
        'APP_INSTANCE_KEY is not configured.',
      );
    }
  }
}
