import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

import '../network/api_exception.dart';
import 'app_instance_config.dart';
import 'bootstrap_result.dart';
import 'channel_context.dart';
import 'environment_config.dart';
import 'startup_performance_policy.dart';

class BootstrapService {
  BootstrapService({
    http.Client? client,
    this.timeout = StartupPerformancePolicy.bootstrapNetworkTimeout,
    String? appInstanceKey,
  })  : _client = client ?? http.Client(),
        _appInstanceKey = appInstanceKey ?? AppInstanceConfig.instanceKey;

  final http.Client _client;
  final Duration timeout;
  final String _appInstanceKey;

  Future<BootstrapResult> load() async {
    final normalizedInstanceKey = _appInstanceKey.trim();

    if (normalizedInstanceKey.isEmpty) {
      throw StateError(
        'APP_INSTANCE_KEY is not configured.',
      );
    }

    final normalizedBaseUrl = EnvironmentConfig.apiBaseUrl.replaceFirst(
      RegExp(r'/+$'),
      '',
    );

    final uri = Uri.parse(
      '$normalizedBaseUrl/api/v1/bootstrap',
    );

    try {
      final response = await _client
          .post(
            uri,
            headers: const {
              'Accept': 'application/json',
              'Content-Type': 'application/json',
            },
            body: jsonEncode({
              'app_instance_key': normalizedInstanceKey,
              'channel': ChannelContext.code,
            }),
          )
          .timeout(timeout);

      dynamic decoded;

      try {
        decoded = jsonDecode(response.body);
      } on FormatException {
        throw ApiException(
          'Invalid bootstrap response.',
          statusCode: response.statusCode,
        );
      }

      if (response.statusCode < 200 || response.statusCode >= 300) {
        throw ApiException(
          'Bootstrap request failed.',
          statusCode: response.statusCode,
          data: decoded,
        );
      }

      if (decoded is! Map<String, dynamic>) {
        throw ApiException(
          'Unexpected bootstrap response format.',
          statusCode: response.statusCode,
          data: decoded,
        );
      }

      final rawData = decoded['data'];

      if (rawData is! Map<String, dynamic>) {
        throw ApiException(
          'Bootstrap response does not contain data.',
          statusCode: response.statusCode,
          data: decoded,
        );
      }

      try {
        return BootstrapResult.fromJson(rawData);
      } on FormatException catch (error) {
        throw ApiException(
          error.message,
          statusCode: response.statusCode,
          data: rawData,
        );
      }
    } on TimeoutException {
      throw const ApiTimeoutException();
    } on ApiException {
      rethrow;
    } catch (_) {
      throw const ApiNetworkException();
    }
  }

  void close() {
    _client.close();
  }
}
