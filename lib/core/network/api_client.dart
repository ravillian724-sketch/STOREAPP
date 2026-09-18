import 'dart:async';
import 'dart:convert';

import 'package:ecommerce_app/core/platform/channel_context.dart';
import 'package:ecommerce_app/core/platform/tenant_context.dart';
import 'package:http/http.dart' as http;

import 'api_exception.dart';
import 'api_response.dart';

class ApiClient {
  static const Duration defaultTimeout = Duration(seconds: 20);

  final Uri baseUri;
  final TenantContext tenantContext;
  final String appInstanceKey;
  final http.Client _client;
  final Duration timeout;

  ApiClient({
    required this.baseUri,
    required this.tenantContext,
    required String appInstanceKey,
    http.Client? client,
    this.timeout = defaultTimeout,
  })  : appInstanceKey = appInstanceKey.trim(),
        _client = client ?? http.Client() {
    if (this.appInstanceKey.isEmpty) {
      throw StateError('APP_INSTANCE_KEY is not configured.');
    }
  }

  Map<String, String> _buildHeaders({
    String? accessToken,
    Map<String, String>? extraHeaders,
  }) {
    final headers = <String, String>{
      'Accept': 'application/json',
      'Content-Type': 'application/json',
      'X-Tenant-Id': tenantContext.tenantId,
      'X-App-Instance-Key': appInstanceKey,
      'X-Channel': ChannelContext.code,
    };

    if (tenantContext.hasBrand) {
      headers['X-Brand-Id'] = tenantContext.brandId!;
    }

    if (tenantContext.hasBranch) {
      headers['X-Branch-Id'] = tenantContext.branchId!;
    }

    if (accessToken != null && accessToken.trim().isNotEmpty) {
      headers['Authorization'] = 'Bearer ${accessToken.trim()}';
    }

    if (extraHeaders != null) {
      const protectedHeaders = <String>{
        'accept',
        'content-type',
        'authorization',
        'x-tenant-id',
        'x-app-instance-key',
        'x-channel',
        'x-brand-id',
        'x-branch-id',
      };

      for (final entry in extraHeaders.entries) {
        final normalizedName = entry.key.trim().toLowerCase();

        if (protectedHeaders.contains(normalizedName)) {
          throw ArgumentError(
            'Request-scoped headers cannot override protected platform headers.',
          );
        }

        headers[entry.key] = entry.value;
      }
    }

    return headers;
  }

  Uri _resolve(
    String path, {
    Map<String, String>? queryParameters,
  }) {
    final normalizedBase = baseUri.toString().endsWith('/')
        ? baseUri
        : Uri.parse('${baseUri.toString()}/');

    final normalizedPath = path.startsWith('/') ? path.substring(1) : path;

    final uri = normalizedBase.resolve(normalizedPath);

    if (queryParameters == null || queryParameters.isEmpty) {
      return uri;
    }

    return uri.replace(
      queryParameters: {
        ...uri.queryParameters,
        ...queryParameters,
      },
    );
  }

  Future<ApiResponse> get(
    String path, {
    Map<String, String>? queryParameters,
    String? accessToken,
    Map<String, String>? extraHeaders,
  }) {
    return _send(
      method: 'GET',
      uri: _resolve(
        path,
        queryParameters: queryParameters,
      ),
      accessToken: accessToken,
      extraHeaders: extraHeaders,
    );
  }

  Future<ApiResponse> post(
    String path, {
    Object? body,
    String? accessToken,
    Map<String, String>? extraHeaders,
  }) {
    return _send(
      method: 'POST',
      uri: _resolve(path),
      body: body,
      accessToken: accessToken,
      extraHeaders: extraHeaders,
    );
  }

  Future<ApiResponse> put(
    String path, {
    Object? body,
    String? accessToken,
    Map<String, String>? extraHeaders,
  }) {
    return _send(
      method: 'PUT',
      uri: _resolve(path),
      body: body,
      accessToken: accessToken,
      extraHeaders: extraHeaders,
    );
  }

  Future<ApiResponse> patch(
    String path, {
    Object? body,
    String? accessToken,
    Map<String, String>? extraHeaders,
  }) {
    return _send(
      method: 'PATCH',
      uri: _resolve(path),
      body: body,
      accessToken: accessToken,
      extraHeaders: extraHeaders,
    );
  }

  Future<ApiResponse> delete(
    String path, {
    Object? body,
    String? accessToken,
    Map<String, String>? extraHeaders,
  }) {
    return _send(
      method: 'DELETE',
      uri: _resolve(path),
      body: body,
      accessToken: accessToken,
      extraHeaders: extraHeaders,
    );
  }

  Future<ApiResponse> _send({
    required String method,
    required Uri uri,
    Object? body,
    String? accessToken,
    Map<String, String>? extraHeaders,
  }) async {
    try {
      final request = http.Request(
        method,
        uri,
      );

      request.headers.addAll(
        _buildHeaders(
          accessToken: accessToken,
          extraHeaders: extraHeaders,
        ),
      );

      if (body != null) {
        request.body = jsonEncode(body);
      }

      final streamedResponse = await _client.send(request).timeout(timeout);

      final response = await http.Response.fromStream(
        streamedResponse,
      );

      dynamic decoded;

      if (response.body.trim().isNotEmpty) {
        try {
          decoded = jsonDecode(response.body);
        } on FormatException {
          decoded = response.body;
        }
      }

      final apiResponse = ApiResponse(
        statusCode: response.statusCode,
        data: decoded,
        headers: response.headers,
      );

      if (!apiResponse.isSuccess) {
        throw ApiException(
          'API request failed',
          statusCode: response.statusCode,
          data: decoded,
        );
      }

      return apiResponse;
    } on TimeoutException {
      throw const ApiTimeoutException();
    } on http.ClientException {
      throw const ApiNetworkException();
    } on ApiException {
      rethrow;
    } on ArgumentError {
      rethrow;
    } catch (_) {
      throw const ApiNetworkException();
    }
  }

  void close() {
    _client.close();
  }
}
