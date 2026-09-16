enum PlatformStartupStatus {
  idle,
  bootstrapping,
  ready,
  networkUnavailable,
  invalidStore,
  serverUnavailable,
  failed,
}

class PlatformStartupState {
  final PlatformStartupStatus status;
  final String? message;
  final int? statusCode;

  const PlatformStartupState({
    required this.status,
    this.message,
    this.statusCode,
  });

  const PlatformStartupState.idle()
      : this(
          status: PlatformStartupStatus.idle,
        );

  const PlatformStartupState.bootstrapping()
      : this(
          status: PlatformStartupStatus.bootstrapping,
        );

  const PlatformStartupState.ready()
      : this(
          status: PlatformStartupStatus.ready,
        );

  const PlatformStartupState.networkUnavailable({
    String? message,
  }) : this(
          status: PlatformStartupStatus.networkUnavailable,
          message: message,
        );

  const PlatformStartupState.invalidStore({
    String? message,
    int? statusCode,
  }) : this(
          status: PlatformStartupStatus.invalidStore,
          message: message,
          statusCode: statusCode,
        );

  const PlatformStartupState.serverUnavailable({
    String? message,
    int? statusCode,
  }) : this(
          status: PlatformStartupStatus.serverUnavailable,
          message: message,
          statusCode: statusCode,
        );

  const PlatformStartupState.failed({
    String? message,
    int? statusCode,
  }) : this(
          status: PlatformStartupStatus.failed,
          message: message,
          statusCode: statusCode,
        );

  bool get isLoading =>
      status == PlatformStartupStatus.bootstrapping;

  bool get isReady =>
      status == PlatformStartupStatus.ready;

  bool get canRetry =>
      status == PlatformStartupStatus.networkUnavailable ||
      status == PlatformStartupStatus.serverUnavailable ||
      status == PlatformStartupStatus.failed;
}
