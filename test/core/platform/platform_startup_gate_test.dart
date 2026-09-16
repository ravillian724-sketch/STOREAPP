import 'dart:async';

import 'package:ecommerce_app/core/platform/platform_startup_bridge.dart';
import 'package:ecommerce_app/core/platform/platform_startup_gate.dart';
import 'package:ecommerce_app/core/platform/platform_startup_state.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  const legacyApp = MaterialApp(
    home: Scaffold(
      body: Text(
        'Legacy App',
        key: ValueKey('legacy-app'),
      ),
    ),
  );

  group('PlatformStartupGate', () {
    testWidgets(
      'bypasses platform startup and preserves legacy app when disabled',
      (tester) async {
        var startupCalled = false;

        final bridge = PlatformStartupBridge(
          enabled: false,
          startPlatform: () async {
            startupCalled = true;
            return const PlatformStartupState.ready();
          },
        );

        await tester.pumpWidget(
          PlatformStartupGate(
            bridge: bridge,
            child: legacyApp,
          ),
        );

        expect(find.byKey(const ValueKey('legacy-app')), findsOneWidget);
        expect(
          find.byKey(const ValueKey('platform-startup-loading')),
          findsNothing,
        );
        expect(startupCalled, isFalse);
        expect(find.byType(MaterialApp), findsOneWidget);
      },
    );

    testWidgets(
      'shows startup loading while platform bootstrap is pending',
      (tester) async {
        final completer = Completer<PlatformStartupState>();

        final bridge = PlatformStartupBridge(
          enabled: true,
          startPlatform: () => completer.future,
        );

        await tester.pumpWidget(
          PlatformStartupGate(
            bridge: bridge,
            child: legacyApp,
          ),
        );

        expect(
          find.byKey(const ValueKey('platform-startup-loading')),
          findsOneWidget,
        );
        expect(find.byKey(const ValueKey('legacy-app')), findsNothing);
        expect(find.byType(MaterialApp), findsOneWidget);

        completer.complete(const PlatformStartupState.ready());
        await tester.pumpAndSettle();

        expect(find.byKey(const ValueKey('legacy-app')), findsOneWidget);
        expect(
          find.byKey(const ValueKey('platform-startup-loading')),
          findsNothing,
        );
        expect(find.byType(MaterialApp), findsOneWidget);
      },
    );

    testWidgets(
      'shows retry for network failure and enters app after retry succeeds',
      (tester) async {
        var attempts = 0;

        final bridge = PlatformStartupBridge(
          enabled: true,
          startPlatform: () async {
            attempts++;

            if (attempts == 1) {
              return const PlatformStartupState.networkUnavailable(
                message: 'offline',
              );
            }

            return const PlatformStartupState.ready();
          },
        );

        await tester.pumpWidget(
          PlatformStartupGate(
            bridge: bridge,
            child: legacyApp,
          ),
        );
        await tester.pumpAndSettle();

        expect(find.text('No internet connection.'), findsOneWidget);
        expect(
          find.byKey(const ValueKey('platform-startup-retry')),
          findsOneWidget,
        );
        expect(find.byKey(const ValueKey('legacy-app')), findsNothing);
        expect(attempts, 1);

        await tester.tap(
          find.byKey(const ValueKey('platform-startup-retry')),
        );
        await tester.pumpAndSettle();

        expect(attempts, 2);
        expect(find.byKey(const ValueKey('legacy-app')), findsOneWidget);
        expect(
          find.byKey(const ValueKey('platform-startup-retry')),
          findsNothing,
        );
      },
    );

    testWidgets(
      'blocks invalid store configuration without retry',
      (tester) async {
        final bridge = PlatformStartupBridge(
          enabled: true,
          startPlatform: () async => const PlatformStartupState.invalidStore(
            message: 'invalid store',
            statusCode: 404,
          ),
        );

        await tester.pumpWidget(
          PlatformStartupGate(
            bridge: bridge,
            child: legacyApp,
          ),
        );
        await tester.pumpAndSettle();

        expect(
          find.text('This store configuration is not valid.'),
          findsOneWidget,
        );
        expect(
          find.byKey(const ValueKey('platform-startup-retry')),
          findsNothing,
        );
        expect(find.byKey(const ValueKey('legacy-app')), findsNothing);
      },
    );

    testWidgets(
      'converts unexpected startup exception into retryable failure UI',
      (tester) async {
        final bridge = PlatformStartupBridge(
          enabled: true,
          startPlatform: () async {
            throw StateError('unexpected');
          },
        );

        await tester.pumpWidget(
          PlatformStartupGate(
            bridge: bridge,
            child: legacyApp,
          ),
        );
        await tester.pumpAndSettle();

        expect(find.text('Unable to start the store.'), findsOneWidget);
        expect(
          find.byKey(const ValueKey('platform-startup-retry')),
          findsOneWidget,
        );
        expect(find.byKey(const ValueKey('legacy-app')), findsNothing);
      },
    );
  });
}
