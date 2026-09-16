import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

import '../network/api_exception.dart';
import 'app_instance_config.dart';
import 'bootstrap_result.dart';
import 'channel_context.dart';
import 'environment_config.dart';

class BootstrapService {
  BootstrapService({
    http.Client? client,
  }) : _client = client ?? http.Client();

  final http.Client _client;

  static const Duration _timeout =
      Duration(seconds: 20);

  Future<BootstrapResult> load() async {
    AppInstanceConfig.validate();

    final normalizedBaseUrl =
        EnvironmentConfig.apiBaseUrl.replaceFirst(
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
              'app_instance_key':
                  AppInstanceConfig.instanceKey,
              'channel': ChannelContext.code,
            }),
          )
          .timeout(_timeout);

      dynamic decoded;

      try {
        decoded = jsonDecode(response.body);
      } on FormatException {
        throw ApiException(
          'Invalid bootstrap response.',
          statusCode: response.statusCode,
        );
      }

      if (response.statusCode < 200 ||
          response.statusCode >= 300) {
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
