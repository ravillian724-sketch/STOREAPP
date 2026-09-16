import 'dart:async';

import 'package:flutter/material.dart';

import 'platform_startup_bridge.dart';
import 'platform_startup_state.dart';
import 'startup_performance_policy.dart';

typedef AppPreparationAction = Future<void> Function();

class PlatformStartupGate extends StatefulWidget {
  const PlatformStartupGate({
    super.key,
    required this.child,
    this.bridge,
    this.prepareApp,
  });

  final Widget child;
  final PlatformStartupBridge? bridge;
  final AppPreparationAction? prepareApp;

  @override
  State<PlatformStartupGate> createState() => _PlatformStartupGateState();
}

class _PlatformStartupGateState extends State<PlatformStartupGate> {
  late final PlatformStartupBridge _bridge;

  PlatformStartupState? _state;
  Future<void>? _preparationFuture;
  bool _isStarting = false;

  @override
  void initState() {
    super.initState();

    _bridge = widget.bridge ?? PlatformStartupBridge();

    if (!_bridge.isEnabled && widget.prepareApp == null) {
      return;
    }

    _start();
  }

  Future<void> _prepareApp() async {
    final action = widget.prepareApp;

    if (action == null) {
      return;
    }

    _preparationFuture ??= Future<void>.sync(action);

    final currentPreparation = _preparationFuture!;

    try {
      await currentPreparation.timeout(
        StartupPerformancePolicy.criticalPreparationTimeout,
      );
    } on TimeoutException {
      // Do not create a second initialization attempt while the
      // original operation is still running.
      rethrow;
    } catch (error, stackTrace) {
      if (identical(
        _preparationFuture,
        currentPreparation,
      )) {
        _preparationFuture = null;
      }

      Error.throwWithStackTrace(
        error,
        stackTrace,
      );
    }
  }

  Future<void> _start() async {
    if (_isStarting) {
      return;
    }

    _isStarting = true;

    if (mounted) {
      setState(() {
        _state = const PlatformStartupState.bootstrapping();
      });
    }

    PlatformStartupState? nextState;

    try {
      final results = await Future.wait<Object?>(
        [
          _bridge.startIfEnabled(),
          _prepareApp(),
        ],
      );

      nextState = results.first as PlatformStartupState?;
    } catch (_) {
      nextState = const PlatformStartupState.failed(
        message: 'Unexpected startup failure.',
      );
    }

    if (!mounted) {
      return;
    }

    setState(() {
      _state = nextState;
      _isStarting = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    final state = _state;

    if (state == null || state.isReady) {
      return widget.child;
    }

    if (state.isLoading) {
      return const _PlatformStartupShell(
        child: _PlatformStartupLoadingView(),
      );
    }

    return _PlatformStartupShell(
      child: _PlatformStartupFailureView(
        state: state,
        onRetry: state.canRetry ? _start : null,
      ),
    );
  }
}

class _PlatformStartupShell extends StatelessWidget {
  const _PlatformStartupShell({
    required this.child,
  });

  final Widget child;

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      debugShowCheckedModeBanner: false,
      home: Scaffold(
        body: SafeArea(
          child: child,
        ),
      ),
    );
  }
}

class _PlatformStartupLoadingView extends StatelessWidget {
  const _PlatformStartupLoadingView();

  @override
  Widget build(BuildContext context) {
    return const Center(
      key: ValueKey(
        'platform-startup-loading',
      ),
      child: CircularProgressIndicator(),
    );
  }
}

class _PlatformStartupFailureView extends StatelessWidget {
  const _PlatformStartupFailureView({
    required this.state,
    required this.onRetry,
  });

  final PlatformStartupState state;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    final message = switch (state.status) {
      PlatformStartupStatus.networkUnavailable => 'No internet connection.',
      PlatformStartupStatus.serverUnavailable =>
        'The service is temporarily unavailable.',
      PlatformStartupStatus.invalidStore =>
        'This store configuration is not valid.',
      _ => 'Unable to start the store.',
    };

    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              message,
              key: const ValueKey(
                'platform-startup-message',
              ),
              textAlign: TextAlign.center,
            ),
            if (onRetry != null) ...[
              const SizedBox(height: 16),
              ElevatedButton(
                key: const ValueKey(
                  'platform-startup-retry',
                ),
                onPressed: onRetry,
                child: const Text('Retry'),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
