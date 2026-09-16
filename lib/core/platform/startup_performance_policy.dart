class StartupPerformancePolicy {
  StartupPerformancePolicy._();

  /// Maximum time the application should block waiting for the initial
  /// platform bootstrap network request.
  static const Duration bootstrapNetworkTimeout = Duration(seconds: 5);
}
