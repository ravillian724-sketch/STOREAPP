import 'package:ecommerce_app/core/network/api_exception.dart';
import 'package:ecommerce_app/core/platform/bootstrap_result.dart';
import 'package:ecommerce_app/core/platform/platform_startup_coordinator.dart';
import 'package:ecommerce_app/core/platform/platform_startup_state.dart';
import 'package:ecommerce_app/core/platform/store_config.dart';
import 'package:flutter_test/flutter_test.dart';

const _bootstrapResult = BootstrapResult(
  storeConfig: StoreConfig(
    tenantId: 'tenant-test',
    nameAr: 'متجر اختبار',
    nameEn: 'Test Store',
  ),
);

void main() {
  group('PlatformStartupCoordinator', () {
    test('starts in idle state', () {
      final coordinator = PlatformStartupCoordinator(
        bootstrap: () async => _bootstrapResult,
      );

      expect(
        coordinator.state.status,
        PlatformStartupStatus.idle,
      );
    });

    test('moves to ready when bootstrap succeeds', () async {
      final coordinator = PlatformStartupCoordinator(
        bootstrap: () async => _bootstrapResult,
      );

      final state = await coordinator.start();

      expect(
        state.status,
        PlatformStartupStatus.ready,
      );
      expect(state.isReady, isTrue);
    });

    test('maps network failure to networkUnavailable',
        () async {
      final coordinator = PlatformStartupCoordinator(
        bootstrap: () async {
          throw const ApiNetworkException();
        },
      );

      final state = await coordinator.start();

      expect(
        state.status,
        PlatformStartupStatus.networkUnavailable,
      );
      expect(state.canRetry, isTrue);
    });

    test('maps timeout to serverUnavailable',
        () async {
      final coordinator = PlatformStartupCoordinator(
        bootstrap: () async {
          throw const ApiTimeoutException();
        },
      );

      final state = await coordinator.start();

      expect(
        state.status,
        PlatformStartupStatus.serverUnavailable,
      );
      expect(state.canRetry, isTrue);
    });

    test('maps 404 to invalidStore', () async {
      final coordinator = PlatformStartupCoordinator(
        bootstrap: () async {
          throw const ApiException(
            'Store not found',
            statusCode: 404,
          );
        },
      );

      final state = await coordinator.start();

      expect(
        state.status,
        PlatformStartupStatus.invalidStore,
      );
      expect(state.statusCode, 404);
      expect(state.canRetry, isFalse);
    });

    test('maps 500 to serverUnavailable',
        () async {
      final coordinator = PlatformStartupCoordinator(
        bootstrap: () async {
          throw const ApiException(
            'Server error',
            statusCode: 500,
          );
        },
      );

      final state = await coordinator.start();

      expect(
        state.status,
        PlatformStartupStatus.serverUnavailable,
      );
      expect(state.canRetry, isTrue);
    });

    test('maps 429 to serverUnavailable',
        () async {
      final coordinator = PlatformStartupCoordinator(
        bootstrap: () async {
          throw const ApiException(
            'Too many requests',
            statusCode: 429,
          );
        },
      );

      final state = await coordinator.start();

      expect(
        state.status,
        PlatformStartupStatus.serverUnavailable,
      );
      expect(state.canRetry, isTrue);
    });

    test('retry succeeds after temporary network failure',
        () async {
      var attempts = 0;

      final coordinator = PlatformStartupCoordinator(
        bootstrap: () async {
          attempts++;

          if (attempts == 1) {
            throw const ApiNetworkException();
          }

          return _bootstrapResult;
        },
      );

      final firstState = await coordinator.start();

      expect(
        firstState.status,
        PlatformStartupStatus.networkUnavailable,
      );

      final retryState = await coordinator.retry();

      expect(
        retryState.status,
        PlatformStartupStatus.ready,
      );
      expect(attempts, 2);
    });
  });
}
