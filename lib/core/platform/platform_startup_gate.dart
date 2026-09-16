import 'package:flutter/material.dart';

import 'platform_startup_bridge.dart';
import 'platform_startup_state.dart';

class PlatformStartupGate extends StatefulWidget {
  const PlatformStartupGate({
    super.key,
    required this.child,
    this.bridge,
  });

  final Widget child;
  final PlatformStartupBridge? bridge;

  @override
  State<PlatformStartupGate> createState() => _PlatformStartupGateState();
}

class _PlatformStartupGateState extends State<PlatformStartupGate> {
  late final PlatformStartupBridge _bridge;

  PlatformStartupState? _state;
  bool _isStarting = false;

  @override
  void initState() {
    super.initState();
    _bridge = widget.bridge ?? PlatformStartupBridge();
    _start();
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

    PlatformStartupState? state;

    try {
      state = await _bridge.startIfEnabled();
    } catch (_) {
      state = const PlatformStartupState.failed(
        message: 'Unexpected startup failure.',
      );
    }

    if (!mounted) {
      return;
    }

    setState(() {
      _state = state;
      _isStarting = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    final state = _state;

    // Transitional migration path:
    // If the platform bootstrap is not enabled yet, preserve the legacy app.
    if (!_bridge.isEnabled || state == null || state.isReady) {
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
      key: ValueKey('platform-startup-loading'),
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
              key: const ValueKey('platform-startup-message'),
              textAlign: TextAlign.center,
            ),
            if (onRetry != null) ...[
              const SizedBox(height: 16),
              ElevatedButton(
                key: const ValueKey('platform-startup-retry'),
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
