import 'dart:convert';
import 'dart:math';

import 'package:ecommerce_app/core/platform/tenant_context.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

abstract interface class OrderSecureStorage {
  Future<String?> read(String key);
  Future<void> write(String key, String value);
  Future<void> delete(String key);
}

class FlutterOrderSecureStorage implements OrderSecureStorage {
  FlutterOrderSecureStorage({
    FlutterSecureStorage? storage,
  }) : _storage = storage ?? const FlutterSecureStorage();

  final FlutterSecureStorage _storage;

  @override
  Future<String?> read(String key) {
    return _storage.read(key: key);
  }

  @override
  Future<void> write(String key, String value) {
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

class OrderAccessStore {
  OrderAccessStore({
    OrderSecureStorage? storage,
    Random? random,
  })  : _storage = storage ?? FlutterOrderSecureStorage(),
        _random = random ?? Random.secure();

  static const _maxRememberedOrders = 50;

  final OrderSecureStorage _storage;
  final Random _random;

  Future<String> checkoutToken(
    TenantContext context, {
    required String cartId,
  }) async {
    final key = _pendingKey(
      context,
      cartId,
    );

    final existing = await _storage.read(key);
    if (_isToken(existing)) {
      return existing!.trim().toLowerCase();
    }

    if (existing != null) {
      await _storage.delete(key);
    }
    final generated = _randomHex(32);
    await _storage.write(
      key,
      generated,
    );

    return generated;
  }

  Future<void> saveOrder(
    TenantContext context, {
    required String cartId,
    required String orderId,
    required String token,
  }) async {
    final normalizedOrderId = _requiredId(
      orderId,
      'orderId',
    );
    final normalizedToken = _requiredToken(token);

    await _storage.write(
      _orderKey(
        context,
        normalizedOrderId,
      ),
      normalizedToken,
    );

    final orderIds = await listOrderIds(context);
    final next = <String>[
      normalizedOrderId,
      ...orderIds.where(
        (id) => id != normalizedOrderId,
      ),
    ].take(_maxRememberedOrders).toList();
    await _storage.write(
      _indexKey(context),
      jsonEncode(next),
    );
  }

  Future<void> clearCheckoutToken(
    TenantContext context, {
    required String cartId,
  }) {
    return _storage.delete(
      _pendingKey(
        context,
        cartId,
      ),
    );
  }

  Future<void> removeOrder(
    TenantContext context,
    String orderId,
  ) async {
    final normalizedOrderId = _requiredId(
      orderId,
      'orderId',
    );

    await _storage.delete(
      _orderKey(
        context,
        normalizedOrderId,
      ),
    );

    final orderIds = await listOrderIds(context);
    final next = orderIds
        .where(
          (id) => id != normalizedOrderId,
        )
        .toList(growable: false);

    if (next.isEmpty) {
      await _storage.delete(
        _indexKey(context),
      );
      return;
    }

    await _storage.write(
      _indexKey(context),
      jsonEncode(next),
    );
  }

  Future<String?> readOrderToken(
    TenantContext context,
    String orderId,
  ) async {
    final raw = await _storage.read(
      _orderKey(
        context,
        orderId,
      ),
    );

    if (!_isToken(raw)) {
      if (raw != null) {
        await _storage.delete(
          _orderKey(
            context,
            orderId,
          ),
        );
      }
      return null;
    }

    return raw!.trim().toLowerCase();
  }

  Future<List<String>> listOrderIds(
    TenantContext context,
  ) async {
    final raw = await _storage.read(
      _indexKey(context),
    );

    if (raw == null || raw.trim().isEmpty) {
      return const [];
    }

    try {
      final decoded = jsonDecode(raw);
      if (decoded is! List) {
        throw const FormatException();
      }

      return decoded
          .map((value) => value.toString().trim())
          .where((value) => value.isNotEmpty)
          .toSet()
          .take(_maxRememberedOrders)
          .toList();
    } on FormatException {
      await _storage.delete(
        _indexKey(context),
      );
      return const [];
    }
  }

  String _pendingKey(
    TenantContext context,
    String cartId,
  ) {
    return 'storeapp.order.v1.${_scope(context)}.pending.'
        '${_encode(_requiredId(cartId, 'cartId'))}';
  }

  String _orderKey(
    TenantContext context,
    String orderId,
  ) {
    return 'storeapp.order.v1.${_scope(context)}.order.'
        '${_encode(_requiredId(orderId, 'orderId'))}';
  }

  String _indexKey(
    TenantContext context,
  ) {
    return 'storeapp.order.v1.${_scope(context)}.index';
  }

  String _scope(
    TenantContext context,
  ) {
    final tenantId = context.tenantId.trim();
    final brandId = context.brandId?.trim() ?? '';

    if (tenantId.isEmpty) {
      throw StateError(
        'Tenant context is required for order storage.',
      );
    }

    return _encode(
      '$tenantId|$brandId',
    );
  }

  String _encode(String value) {
    return base64Url
        .encode(
          utf8.encode(value),
        )
        .replaceAll('=', '');
  }

  String _requiredId(
    String value,
    String field,
  ) {
    final normalized = value.trim();
    if (normalized.isEmpty) {
      throw ArgumentError.value(
        value,
        field,
        '$field must not be empty.',
      );
    }
    return normalized;
  }

  String _requiredToken(String value) {
    final normalized = value.trim().toLowerCase();
    if (!_isToken(normalized)) {
      throw ArgumentError.value(
        value,
        'token',
        'Order token must contain exactly 64 hexadecimal characters.',
      );
    }
    return normalized;
  }

  bool _isToken(String? value) {
    return RegExp(
      r'^[0-9a-fA-F]{64}$',
    ).hasMatch(
      value?.trim() ?? '',
    );
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
