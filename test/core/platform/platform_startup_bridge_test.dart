import 'package:ecommerce_app/core/platform/platform_startup_bridge.dart';
import 'package:ecommerce_app/core/platform/platform_startup_state.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  group('PlatformStartupBridge', () {
    test('skips platform startup when disabled', () async {
      var startupCalled = false;

      final bridge = PlatformStartupBridge(
        enabled: false,
        startPlatform: () async {
          startupCalled = true;
          return const PlatformStartupState.ready();
        },
      );

      final state = await bridge.startIfEnabled();

      expect(state, isNull);
      expect(startupCalled, isFalse);
      expect(bridge.isEnabled, isFalse);
    });

    test('starts platform when enabled', () async {
      var startupCalled = false;

      final bridge = PlatformStartupBridge(
        enabled: true,
        startPlatform: () async {
          startupCalled = true;
          return const PlatformStartupState.ready();
        },
      );

      final state = await bridge.startIfEnabled();

      expect(startupCalled, isTrue);
      expect(state?.status, PlatformStartupStatus.ready);
      expect(bridge.isEnabled, isTrue);
    });

    test('returns startup failure state unchanged', () async {
      final bridge = PlatformStartupBridge(
        enabled: true,
        startPlatform: () async =>
            const PlatformStartupState.networkUnavailable(
          message: 'offline',
        ),
      );

      final state = await bridge.startIfEnabled();

      expect(
        state?.status,
        PlatformStartupStatus.networkUnavailable,
      );
      expect(state?.canRetry, isTrue);
    });
  });
}
