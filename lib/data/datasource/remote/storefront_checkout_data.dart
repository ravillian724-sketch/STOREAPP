import 'package:ecommerce_app/core/cart/cart_session.dart';
import 'package:ecommerce_app/core/cart/cart_session_store.dart';
import 'package:ecommerce_app/core/network/api_client.dart';
import 'package:ecommerce_app/core/order/order_access_store.dart';
import 'package:ecommerce_app/core/platform/platform_service.dart';
import 'package:ecommerce_app/core/platform/tenant_context.dart';
import 'package:ecommerce_app/data/model/storefront_checkout_model.dart';

class StorefrontCheckoutData {
  StorefrontCheckoutData({
    ApiClient? apiClient,
    TenantContext? tenantContext,
    CartSessionStore? sessionStore,
    OrderAccessStore? orderAccessStore,
  })  : _apiClient = apiClient,
        _tenantContext = tenantContext,
        _sessions = sessionStore ?? CartSessionStore(),
        _orders = orderAccessStore ?? OrderAccessStore();

  final ApiClient? _apiClient;
  final TenantContext? _tenantContext;
  final CartSessionStore _sessions;
  final OrderAccessStore _orders;

  ApiClient get _client {
    final client = _apiClient ?? PlatformService.instance.apiClient;
    if (client == null) {
      throw StateError(
        'PlatformService must be initialized before checkout requests.',
      );
    }
    return client;
  }

  TenantContext get _context {
    final context = _tenantContext ?? PlatformService.instance.tenantContext;
    if (context == null || !context.hasBranch) {
      throw StateError(
        'Tenant and branch context are required for checkout.',
      );
    }
    return context;
  }

  Future<StorefrontCheckoutQuote> quote() async {
    final session = await _requiredSession();
    final response = await _client.post(
      '/api/v1/storefront/carts/'
      '${session.cartId}/checkout/quote',
      extraHeaders: {'X-Cart-Token': session.token},
    );

    return StorefrontCheckoutQuote.fromJson(
      _data(response.data),
    );
  }

  Future<StorefrontCheckoutOrder> createOrder({
    String? customerName,
    String? customerPhone,
    String? customerEmail,
    Map<String, dynamic>? shippingAddress,
  }) async {
    final session = await _requiredSession();
    final context = _context;
    final orderToken = await _orders.checkoutToken(
      context,
      cartId: session.cartId,
    );
    final body = <String, dynamic>{};

    if (customerName?.trim().isNotEmpty == true) {
      body['customer_name'] = customerName!.trim();
    }
    if (customerPhone?.trim().isNotEmpty == true) {
      body['customer_phone'] = customerPhone!.trim();
    }
    if (customerEmail?.trim().isNotEmpty == true) {
      body['customer_email'] = customerEmail!.trim();
    }
    if (shippingAddress != null) {
      body['shipping_address'] = shippingAddress;
    }

    final response = await _client.post(
      '/api/v1/storefront/carts/'
      '${session.cartId}/checkout/order',
      body: body,
      extraHeaders: {
        'X-Cart-Token': session.token,
        'X-Order-Token': orderToken,
      },
    );

    final order = StorefrontCheckoutOrder.fromJson(
      _data(response.data),
    );

    await _orders.saveOrder(
      context,
      cartId: session.cartId,
      orderId: order.id,
      token: orderToken,
    );

    return order;
  }

  Future<StorefrontPaymentAttemptResult> createPaymentAttempt({
    required String providerCode,
    required String methodCode,
  }) async {
    final session = await _requiredSession();
    final context = _context;

    final operation =
        'checkout-payment-attempt:${providerCode.trim().toLowerCase()}:'
        '${methodCode.trim().toLowerCase()}';

    final key = await _sessions.idempotencyKey(
      context,
      operation: operation,
    );

    final response = await _client.post(
      '/api/v1/storefront/carts/'
      '${session.cartId}/checkout/payment-attempts',
      body: {
        'provider_code': providerCode,
        'method_code': methodCode,
      },
      extraHeaders: {
        'X-Cart-Token': session.token,
        'Idempotency-Key': key,
      },
    );

    final result = StorefrontPaymentAttemptResult.fromJson(
      _data(response.data),
    );

    await _sessions.clearIdempotencyKey(
      context,
      operation: operation,
    );

    return result;
  }

  Future<StorefrontSandboxSettlement> settleSandboxPayment({
    required String attemptId,
    required String scenario,
  }) async {
    final normalizedAttemptId = attemptId.trim();
    final normalizedScenario = scenario.trim().toLowerCase();

    if (normalizedAttemptId.isEmpty) {
      throw StateError(
        'Sandbox settlement requires a payment attempt.',
      );
    }

    if (!const <String>{
      'success',
      'decline',
      'cancel',
    }.contains(normalizedScenario)) {
      throw ArgumentError.value(
        scenario,
        'scenario',
        'Unsupported sandbox payment scenario.',
      );
    }

    final session = await _requiredSession();

    final response = await _client.post(
      '/api/v1/storefront/carts/'
      '${session.cartId}/checkout/payment-attempts/'
      '$normalizedAttemptId/sandbox/settle',
      body: {
        'scenario': normalizedScenario,
      },
      extraHeaders: {
        'X-Cart-Token': session.token,
      },
    );

    final result = StorefrontSandboxSettlement.fromJson(
      _data(response.data),
    );

    if (result.succeeded) {
      try {
        await _orders.clearCheckoutToken(
          _context,
          cartId: session.cartId,
        );
      } catch (_) {
        // The remote payment result is authoritative. Local credential
        // cleanup is best-effort after a confirmed purchase.
      }

      try {
        await _sessions.clearSession(
          _context,
        );
      } catch (_) {
        // StorefrontCartData can recover a stale converted cart later.
      }
    }

    return result;
  }

  Future<CartSession> _requiredSession() async {
    final session = await _sessions.readSession(
      _context,
    );

    if (session == null) {
      throw StateError(
        'A platform cart session is required before checkout.',
      );
    }

    return session;
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

    return Map<String, dynamic>.from(data);
  }
}
