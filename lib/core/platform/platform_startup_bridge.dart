import 'app_instance_config.dart';
import 'platform_startup_coordinator.dart';
import 'platform_startup_state.dart';

typedef PlatformStartupAction = Future<PlatformStartupState> Function();

class PlatformStartupBridge {
  PlatformStartupBridge({
    bool? enabled,
    PlatformStartupAction? startPlatform,
  })  : _enabled = enabled ?? AppInstanceConfig.isConfigured,
        _startPlatform = startPlatform ?? PlatformStartupCoordinator().start;

  final bool _enabled;
  final PlatformStartupAction _startPlatform;

  bool get isEnabled => _enabled;

  Future<PlatformStartupState?> startIfEnabled() async {
    if (!_enabled) {
      return null;
    }

    return _startPlatform();
  }
}
