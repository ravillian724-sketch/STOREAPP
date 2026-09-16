class StartupPerformancePolicy {
  StartupPerformancePolicy._();

  // Remote bootstrap must never block startup indefinitely.
  static const Duration bootstrapNetworkTimeout = Duration(seconds: 5);

  // Critical local initialization gets a tighter startup budget.
  static const Duration criticalPreparationTimeout = Duration(seconds: 3);
}
