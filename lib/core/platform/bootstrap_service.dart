import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

import '../logging/app_logger.dart';
import '../network/api_exception.dart';
import 'app_instance_config.dart';
import 'bootstrap_result.dart';
import 'channel_context.dart';
import 'environment_config.dart';
import 'startup_performance_policy.dart';

class BootstrapService {
  BootstrapService({
    http.Client? client,
    Duration? timeout,
    String? appInstanceKey,
    String? apiBaseUrl,
  })  : _client = client ?? http.Client(),
        timeout = timeout ?? StartupPerformancePolicy.bootstrapNetworkTimeout,
        _appInstanceKey = appInstanceKey ?? AppInstanceConfig.instanceKey,
        _apiBaseUrl = apiBaseUrl;

  final http.Client _client;
  final Duration timeout;
  final String _appInstanceKey;
  final String? _apiBaseUrl;

  Future<BootstrapResult> load() async {
    final normalizedInstanceKey = _appInstanceKey.trim();

    if (normalizedInstanceKey.isEmpty) {
      throw StateError(
        'APP_INSTANCE_KEY is not configured.',
      );
    }

    final baseUri = EnvironmentConfig.requireApiBaseUri(_apiBaseUrl);
    final basePath = baseUri.path == '/' ? '' : baseUri.path;
    final uri = baseUri.replace(
      path: '$basePath/api/v1/bootstrap',
      query: null,
      fragment: null,
    );

    final stopwatch = Stopwatch()..start();

    try {
      final response = await _client
          .post(
            uri,
            headers: {
              'Accept': 'application/json',
              'Content-Type': 'application/json',
              'X-App-Instance-Key': normalizedInstanceKey,
            },
            body: jsonEncode({
              'channel': ChannelContext.code,
            }),
          )
          .timeout(timeout);

      appDebugLog(
        'Bootstrap HTTP completed: status=${response.statusCode} '
        'elapsedMs=${stopwatch.elapsedMilliseconds}',
      );

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
      appDebugLog(
        'Bootstrap HTTP timeout: elapsedMs=${stopwatch.elapsedMilliseconds}',
      );
      throw const ApiTimeoutException();
    } on ApiException catch (error) {
      appDebugLog(
        'Bootstrap API error: status=${error.statusCode} '
        'elapsedMs=${stopwatch.elapsedMilliseconds}',
      );
      rethrow;
    } on http.ClientException catch (error, stackTrace) {
      appDebugLog(
        'Bootstrap network error: elapsedMs=${stopwatch.elapsedMilliseconds} '
        'error=$error',
      );
      appDebugLog(stackTrace);
      throw const ApiNetworkException();
    } catch (error, stackTrace) {
      appDebugLog(
        'Bootstrap unexpected error: type=${error.runtimeType} '
        'elapsedMs=${stopwatch.elapsedMilliseconds} error=$error',
      );
      appDebugLog(stackTrace);
      throw const ApiException(
        'Unexpected bootstrap processing failure.',
      );
    }
  }

  void close() {
    _client.close();
  }
}
