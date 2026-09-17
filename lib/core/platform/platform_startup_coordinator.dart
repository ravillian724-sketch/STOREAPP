import '../network/api_exception.dart';
import 'bootstrap_result.dart';
import 'platform_service.dart';
import 'platform_startup_state.dart';

typedef PlatformBootstrap = Future<BootstrapResult> Function();

class PlatformStartupCoordinator {
  PlatformStartupCoordinator({
    PlatformBootstrap? bootstrap,
  }) : _bootstrap = bootstrap ?? PlatformService.instance.bootstrap;

  final PlatformBootstrap _bootstrap;

  PlatformStartupState _state = const PlatformStartupState.idle();

  PlatformStartupState get state => _state;

  Future<PlatformStartupState> start() async {
    _state = const PlatformStartupState.bootstrapping();

    try {
      await _bootstrap();

      _state = const PlatformStartupState.ready();
    } on ApiNetworkException catch (error) {
      _state = PlatformStartupState.networkUnavailable(
        message: error.message,
      );
    } on ApiTimeoutException catch (error) {
      _state = PlatformStartupState.serverUnavailable(
        message: error.message,
      );
    } on ApiException catch (error) {
      _state = _mapApiError(error);
    } catch (_) {
      _state = const PlatformStartupState.failed(
        message: 'Platform startup failed.',
      );
    }

    return _state;
  }

  Future<PlatformStartupState> retry() {
    if (!_state.canRetry) {
      return Future.value(_state);
    }

    return start();
  }

  PlatformStartupState _mapApiError(
    ApiException error,
  ) {
    final statusCode = error.statusCode;

    if (statusCode == 400 ||
        statusCode == 401 ||
        statusCode == 403 ||
        statusCode == 404 ||
        statusCode == 410) {
      return PlatformStartupState.invalidStore(
        message: error.message,
        statusCode: statusCode,
      );
    }

    if (statusCode == 408 ||
        statusCode == 429 ||
        (statusCode != null && statusCode >= 500)) {
      return PlatformStartupState.serverUnavailable(
        message: error.message,
        statusCode: statusCode,
      );
    }

    return PlatformStartupState.failed(
      message: error.message,
      statusCode: statusCode,
    );
  }
}
