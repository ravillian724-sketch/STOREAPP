import 'dart:async';

import 'package:ecommerce_app/core/platform/platform_startup_bridge.dart';
import 'package:ecommerce_app/core/platform/platform_startup_gate.dart';
import 'package:ecommerce_app/core/platform/platform_startup_state.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  const storeApp = MaterialApp(
    home: Scaffold(
      body: Text(
        'Store App',
        key: ValueKey('store-app'),
      ),
    ),
  );

  group(
    'PlatformStartupGate parallel startup',
    () {
      testWidgets(
        'starts platform and preparation concurrently',
        (tester) async {
          final platformCompleter = Completer<PlatformStartupState>();

          final preparationCompleter = Completer<void>();

          var platformStarted = false;
          var preparationStarted = false;

          final bridge = PlatformStartupBridge(
            enabled: true,
            startPlatform: () {
              platformStarted = true;
              return platformCompleter.future;
            },
          );

          await tester.pumpWidget(
            PlatformStartupGate(
              bridge: bridge,
              prepareApp: () {
                preparationStarted = true;
                return preparationCompleter.future;
              },
              child: storeApp,
            ),
          );

          expect(platformStarted, isTrue);
          expect(preparationStarted, isTrue);

          expect(
            find.byKey(
              const ValueKey(
                'platform-startup-loading',
              ),
            ),
            findsOneWidget,
          );

          expect(
            find.byKey(
              const ValueKey('store-app'),
            ),
            findsNothing,
          );

          platformCompleter.complete(
            const PlatformStartupState.ready(),
          );

          await tester.pump();

          // Local preparation is still running,
          // so commerce remains protected.
          expect(
            find.byKey(
              const ValueKey(
                'platform-startup-loading',
              ),
            ),
            findsOneWidget,
          );

          preparationCompleter.complete();

          await tester.pumpAndSettle();

          expect(
            find.byKey(
              const ValueKey('store-app'),
            ),
            findsOneWidget,
          );
        },
      );

      testWidgets(
        'waits for critical preparation when platform is disabled',
        (tester) async {
          final preparationCompleter = Completer<void>();

          var platformCalled = false;

          final bridge = PlatformStartupBridge(
            enabled: false,
            startPlatform: () async {
              platformCalled = true;

              return const PlatformStartupState.ready();
            },
          );

          await tester.pumpWidget(
            PlatformStartupGate(
              bridge: bridge,
              prepareApp: () => preparationCompleter.future,
              child: storeApp,
            ),
          );

          expect(platformCalled, isFalse);

          expect(
            find.byKey(
              const ValueKey(
                'platform-startup-loading',
              ),
            ),
            findsOneWidget,
          );

          preparationCompleter.complete();

          await tester.pumpAndSettle();

          expect(
            find.byKey(
              const ValueKey('store-app'),
            ),
            findsOneWidget,
          );
        },
      );

      testWidgets(
        'fails safely when critical preparation exceeds budget',
        (tester) async {
          final preparationCompleter = Completer<void>();

          final bridge = PlatformStartupBridge(
            enabled: true,
            startPlatform: () async => const PlatformStartupState.ready(),
          );

          await tester.pumpWidget(
            PlatformStartupGate(
              bridge: bridge,
              prepareApp: () => preparationCompleter.future,
              child: storeApp,
            ),
          );

          await tester.pump(
            const Duration(seconds: 4),
          );

          expect(
            find.text(
              'Unable to start the store.',
            ),
            findsOneWidget,
          );

          expect(
            find.byKey(
              const ValueKey(
                'platform-startup-retry',
              ),
            ),
            findsOneWidget,
          );

          preparationCompleter.complete();
        },
      );
    },
  );
}
