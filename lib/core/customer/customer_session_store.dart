import 'dart:convert';

import 'package:ecommerce_app/core/platform/tenant_context.dart';
import 'package:ecommerce_app/data/model/storefront_customer_model.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

abstract interface class CustomerSecureStorage {
  Future<String?> read(String key);
  Future<void> write(String key, String value);
  Future<void> delete(String key);
}

class FlutterCustomerSecureStorage implements CustomerSecureStorage {
  FlutterCustomerSecureStorage({
    FlutterSecureStorage? storage,
  }) : _storage = storage ?? const FlutterSecureStorage();

  final FlutterSecureStorage _storage;

  @override
  Future<String?> read(String key) {
    return _storage.read(key: key);
  }

  @override
  Future<void> write(
    String key,
    String value,
  ) {
    return _storage.write(
      key: key,
      value: value,
    );
  }

  @override
  Future<void> delete(String key) {
    return _storage.delete(key: key);
  }
}

class CustomerSessionStore {
  CustomerSessionStore({
    CustomerSecureStorage? storage,
  }) : _storage = storage ?? FlutterCustomerSecureStorage();

  final CustomerSecureStorage _storage;

  Future<StorefrontCustomerSession?> readSession(
    TenantContext context,
  ) async {
    final key = _sessionKey(context);
    final raw = await _storage.read(key);

    if (raw == null || raw.trim().isEmpty) {
      return null;
    }

    try {
      final decoded = jsonDecode(raw);

      if (decoded is! Map) {
        throw const FormatException(
          'Customer session payload must be a JSON object.',
        );
      }

      final session = StorefrontCustomerSession.fromJson(
        Map<String, dynamic>.from(decoded),
      );

      if (session.isExpired) {
        await _storage.delete(key);
        return null;
      }

      return session;
    } on FormatException {
      await _storage.delete(key);
      return null;
    }
  }

  Future<void> saveSession(
    TenantContext context,
    StorefrontCustomerSession session,
  ) {
    final token = session.accessToken.trim();

    if (token.isEmpty) {
      throw const FormatException(
        'Customer access token must not be empty.',
      );
    }

    return _storage.write(
      _sessionKey(context),
      jsonEncode(session.toJson()),
    );
  }

  Future<void> clearSession(
    TenantContext context,
  ) {
    return _storage.delete(
      _sessionKey(context),
    );
  }

  String _sessionKey(
    TenantContext context,
  ) {
    final tenantId = context.tenantId.trim();

    if (tenantId.isEmpty) {
      throw StateError(
        'Tenant context is required for customer storage.',
      );
    }

    final encodedTenant = base64Url
        .encode(
          utf8.encode(tenantId),
        )
        .replaceAll('=', '');

    return 'storeapp.customer.v1.$encodedTenant.session';
  }
}
