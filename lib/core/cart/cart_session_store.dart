import 'dart:convert';
import 'dart:math';

import 'package:ecommerce_app/core/platform/tenant_context.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import 'cart_session.dart';

abstract interface class CartSecureStorage {
  Future<String?> read(String key);
  Future<void> write(String key, String value);
  Future<void> delete(String key);
}

class FlutterCartSecureStorage implements CartSecureStorage {
  FlutterCartSecureStorage({
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

class CartSessionStore {
  CartSessionStore({
    CartSecureStorage? storage,
    Random? random,
  })  : _storage = storage ?? FlutterCartSecureStorage(),
        _random = random ?? Random.secure();

  final CartSecureStorage _storage;
  final Random _random;

  Future<CartSession?> readSession(
    TenantContext context,
  ) async {
    final raw = await _storage.read(
      _sessionKey(context),
    );

    if (raw == null || raw.trim().isEmpty) {
      return null;
    }

    try {
      final decoded = jsonDecode(raw);

      if (decoded is! Map) {
        throw const FormatException(
          'Cart session payload must be a JSON object.',
        );
      }

      return CartSession.fromJson(
        Map<String, dynamic>.from(decoded),
      );
    } on FormatException {
      await clearSession(context);
      return null;
    }
  }

  Future<void> saveSession(
    TenantContext context,
    CartSession session,
  ) {
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

  Future<String> idempotencyKey(
    TenantContext context, {
    required String operation,
  }) async {
    final key = _operationKey(
      context,
      operation,
    );

    final existing = await _storage.read(key);

    if (existing != null && existing.trim().isNotEmpty) {
      return existing.trim();
    }

    final generated = _randomHex(32);
    await _storage.write(
      key,
      generated,
    );

    return generated;
  }

  Future<void> clearIdempotencyKey(
    TenantContext context, {
    required String operation,
  }) {
    return _storage.delete(
      _operationKey(
        context,
        operation,
      ),
    );
  }

  String _sessionKey(
    TenantContext context,
  ) {
    return 'storeapp.cart.v1.${_scope(context)}.session';
  }

  String _operationKey(
    TenantContext context,
    String operation,
  ) {
    final normalized = operation.trim();

    if (normalized.isEmpty) {
      throw ArgumentError.value(
        operation,
        'operation',
        'Cart operation must not be empty.',
      );
    }

    final encodedOperation = base64Url
        .encode(
          utf8.encode(normalized),
        )
        .replaceAll('=', '');

    return 'storeapp.cart.v1.${_scope(context)}.op.$encodedOperation';
  }

  String _scope(
    TenantContext context,
  ) {
    final tenantId = context.tenantId.trim();
    final branchId = context.branchId?.trim() ?? '';

    if (tenantId.isEmpty) {
      throw StateError(
        'Tenant context is required for cart storage.',
      );
    }

    if (branchId.isEmpty) {
      throw StateError(
        'Branch context is required for cart storage.',
      );
    }

    return base64Url
        .encode(
          utf8.encode('$tenantId|$branchId'),
        )
        .replaceAll('=', '');
  }

  String _randomHex(int byteLength) {
    final bytes = List<int>.generate(
      byteLength,
      (_) => _random.nextInt(256),
      growable: false,
    );

    final buffer = StringBuffer();

    for (final byte in bytes) {
      buffer.write(
        byte.toRadixString(16).padLeft(2, '0'),
      );
    }

    return buffer.toString();
  }
}
