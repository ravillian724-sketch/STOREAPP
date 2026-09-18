import 'package:ecommerce_app/core/cart/cart_session.dart';
import 'package:ecommerce_app/core/cart/cart_session_store.dart';
import 'package:ecommerce_app/core/network/api_client.dart';
import 'package:ecommerce_app/core/network/api_exception.dart';
import 'package:ecommerce_app/core/platform/platform_service.dart';
import 'package:ecommerce_app/core/platform/tenant_context.dart';
import 'package:ecommerce_app/data/model/storefront_cart_model.dart';

class StorefrontCartData {
  StorefrontCartData({
    ApiClient? apiClient,
    TenantContext? tenantContext,
    CartSessionStore? sessionStore,
  })  : _apiClient = apiClient,
        _tenantContext = tenantContext,
        _sessions = sessionStore ?? CartSessionStore();

  final ApiClient? _apiClient;
  final TenantContext? _tenantContext;
  final CartSessionStore _sessions;

  ApiClient get _client {
    final client = _apiClient ?? PlatformService.instance.apiClient;

    if (client == null) {
      throw StateError(
        'PlatformService must be initialized before cart requests.',
      );
    }

    return client;
  }

  TenantContext get _context {
    final context = _tenantContext ?? PlatformService.instance.tenantContext;

    if (context == null) {
      throw StateError(
        'Tenant context is unavailable.',
      );
    }

    if (!context.hasBranch) {
      throw StateError(
        'Branch context is required for cart requests.',
      );
    }

    return context;
  }

  Future<StorefrontCartSnapshot> load() async {
    final context = _context;
    final session = await _sessions.readSession(
      context,
    );

    if (session == null) {
      return _createCart(context).then(
        (result) => result.snapshot,
      );
    }

    try {
      return await _show(session);
    } on ApiException catch (error) {
      if (!_isRecoverableStaleCartError(error)) {
        rethrow;
      }

      await _sessions.clearSession(
        context,
      );

      return _createCart(context).then(
        (result) => result.snapshot,
      );
    }
  }

  Future<StorefrontCartSnapshot> addSku({
    required String skuId,
    int quantity = 1,
  }) async {
    final normalizedSkuId = _positiveId(
      skuId,
      'skuId',
    );

    if (quantity < 1) {
      throw ArgumentError.value(
        quantity,
        'quantity',
        'Quantity must be positive.',
      );
    }

    final context = _context;
    var session = await _sessions.readSession(
      context,
    );

    session ??= (await _createCart(context)).session;

    final operation = 'add-sku:$normalizedSkuId:quantity:$quantity';

    final idempotencyKey = await _sessions.idempotencyKey(
      context,
      operation: operation,
    );

    try {
      final snapshot = await _add(
        session: session,
        skuId: normalizedSkuId,
        quantity: quantity,
        idempotencyKey: idempotencyKey,
      );

      await _sessions.clearIdempotencyKey(
        context,
        operation: operation,
      );

      return snapshot;
    } on ApiException catch (error) {
      if (!_isRecoverableStaleCartError(error)) {
        rethrow;
      }

      await _sessions.clearSession(
        context,
      );

      session = (await _createCart(context)).session;

      final snapshot = await _add(
        session: session,
        skuId: normalizedSkuId,
        quantity: quantity,
        idempotencyKey: idempotencyKey,
      );

      await _sessions.clearIdempotencyKey(
        context,
        operation: operation,
      );

      return snapshot;
    }
  }

  Future<StorefrontCartSnapshot> setItemQuantity({
    required String cartItemId,
    required int quantity,
  }) async {
    final normalizedItemId = _requiredText(
      cartItemId,
      'cartItemId',
    );

    if (quantity < 1) {
      throw ArgumentError.value(
        quantity,
        'quantity',
        'Quantity must be positive.',
      );
    }

    final session = await _requiredSession();

    final response = await _client.patch(
      '/api/v1/storefront/carts/'
      '${session.cartId}/items/'
      '$normalizedItemId',
      body: {
        'quantity': quantity,
      },
      extraHeaders: {
        'X-Cart-Token': session.token,
      },
    );

    return _snapshot(response.data);
  }

  Future<StorefrontCartSnapshot> removeItem({
    required String cartItemId,
  }) async {
    final normalizedItemId = _requiredText(
      cartItemId,
      'cartItemId',
    );

    final session = await _requiredSession();

    final response = await _client.delete(
      '/api/v1/storefront/carts/'
      '${session.cartId}/items/'
      '$normalizedItemId',
      extraHeaders: {
        'X-Cart-Token': session.token,
      },
    );

    return _snapshot(response.data);
  }

  Future<StorefrontCartSnapshot> incrementSku(
    String skuId,
  ) {
    return addSku(
      skuId: skuId,
      quantity: 1,
    );
  }

  Future<StorefrontCartSnapshot> decrementSku(
    String skuId,
  ) async {
    final normalizedSkuId = _positiveId(
      skuId,
      'skuId',
    );

    final snapshot = await load();

    final item = snapshot.items
        .where(
          (candidate) => candidate.skuId == normalizedSkuId,
        )
        .firstOrNull;

    if (item == null) {
      return snapshot;
    }

    if (item.quantity <= 1) {
      return removeItem(
        cartItemId: item.cartItemId,
      );
    }

    return setItemQuantity(
      cartItemId: item.cartItemId,
      quantity: item.quantity - 1,
    );
  }

  Future<int> quantityForSku(
    String skuId,
  ) async {
    final normalizedSkuId = _positiveId(
      skuId,
      'skuId',
    );

    final snapshot = await load();

    for (final item in snapshot.items) {
      if (item.skuId == normalizedSkuId) {
        return item.quantity;
      }
    }

    return 0;
  }

  Future<_CreatedCartResult> _createCart(
    TenantContext context,
  ) async {
    final creationKey = await _sessions.idempotencyKey(
      context,
      operation: 'create-cart',
    );

    final response = await _client.post(
      '/api/v1/storefront/carts',
      extraHeaders: {
        'Idempotency-Key': creationKey,
      },
    );

    final data = _data(response.data);
    final token = _requiredText(
      data['cart_token'],
      'data.cart_token',
    );

    final snapshot = StorefrontCartSnapshot.fromJson(
      data,
    );

    final session = CartSession(
      cartId: snapshot.cartId,
      token: token,
    );

    await _sessions.saveSession(
      context,
      session,
    );

    await _sessions.clearIdempotencyKey(
      context,
      operation: 'create-cart',
    );

    return _CreatedCartResult(
      session: session,
      snapshot: snapshot,
    );
  }

  Future<StorefrontCartSnapshot> _show(
    CartSession session,
  ) async {
    final response = await _client.get(
      '/api/v1/storefront/carts/'
      '${session.cartId}',
      extraHeaders: {
        'X-Cart-Token': session.token,
      },
    );

    return _snapshot(response.data);
  }

  Future<StorefrontCartSnapshot> _add({
    required CartSession session,
    required String skuId,
    required int quantity,
    required String idempotencyKey,
  }) async {
    final response = await _client.post(
      '/api/v1/storefront/carts/'
      '${session.cartId}/items',
      body: {
        'sku_id': int.parse(skuId),
        'quantity': quantity,
      },
      extraHeaders: {
        'X-Cart-Token': session.token,
        'Idempotency-Key': idempotencyKey,
      },
    );

    return _snapshot(response.data);
  }

  Future<CartSession> _requiredSession() async {
    final context = _context;
    final session = await _sessions.readSession(
      context,
    );

    if (session != null) {
      return session;
    }

    return (await _createCart(context)).session;
  }

  StorefrontCartSnapshot _snapshot(
    dynamic raw,
  ) {
    return StorefrontCartSnapshot.fromJson(
      _data(raw),
    );
  }

  Map<String, dynamic> _data(dynamic raw) {
    if (raw is! Map) {
      throw const FormatException(
        'API response must be a JSON object.',
      );
    }

    final envelope = Map<String, dynamic>.from(raw);
    final data = envelope['data'];

    if (data is! Map) {
      throw const FormatException(
        'API response does not contain data.',
      );
    }

    return Map<String, dynamic>.from(
      data,
    );
  }

  bool _isRecoverableStaleCartError(ApiException error) {
    return const <String>{
      'CART_NOT_FOUND',
      'CART_NOT_MUTABLE',
    }.contains(
      _errorCode(error),
    );
  }

  String? _errorCode(ApiException error) {
    final raw = error.data;

    if (raw is! Map) {
      return null;
    }

    final envelope = Map<String, dynamic>.from(raw);
    final rawError = envelope['error'];

    if (rawError is! Map) {
      return null;
    }

    return Map<String, dynamic>.from(
      rawError,
    )['code']
        ?.toString();
  }

  String _positiveId(
    String value,
    String name,
  ) {
    final normalized = _requiredText(value, name);
    final parsed = int.tryParse(
      normalized,
    );

    if (parsed == null || parsed < 1) {
      throw ArgumentError.value(
        value,
        name,
        'Must be a positive integer identifier.',
      );
    }

    return parsed.toString();
  }

  String _requiredText(
    dynamic value,
    String name,
  ) {
    final normalized = value?.toString().trim() ?? '';

    if (normalized.isEmpty) {
      throw FormatException(
        '$name is required.',
      );
    }

    return normalized;
  }
}

class _CreatedCartResult {
  final CartSession session;
  final StorefrontCartSnapshot snapshot;

  const _CreatedCartResult({
    required this.session,
    required this.snapshot,
  });
}

extension _FirstOrNull<T> on Iterable<T> {
  T? get firstOrNull {
    final iterator = this.iterator;

    if (!iterator.moveNext()) {
      return null;
    }

    return iterator.current;
  }
}
