import 'dart:convert';
import 'dart:math';

import 'package:ecommerce_app/core/cart/cart_session.dart';
import 'package:ecommerce_app/core/cart/cart_session_store.dart';
import 'package:ecommerce_app/core/network/api_client.dart';
import 'package:ecommerce_app/core/network/api_exception.dart';
import 'package:ecommerce_app/core/platform/tenant_context.dart';
import 'package:ecommerce_app/data/datasource/remote/storefront_checkout_data.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

class _MemoryStorage implements CartSecureStorage {
  _MemoryStorage({
    this.throwOnDelete = false,
  });

  final Map<String, String> values = {};
  final bool throwOnDelete;

  @override
  Future<void> delete(String key) async {
    if (throwOnDelete) {
      throw StateError('simulated secure storage delete failure');
    }

    values.remove(key);
  }

  @override
  Future<String?> read(String key) async {
    return values[key];
  }

  @override
  Future<void> write(String key, String value) async {
    values[key] = value;
  }
}

const _context = TenantContext(
  tenantId: 'tenant-1',
  branchId: '10',
);

ApiClient _api(http.Client client) {
  return ApiClient(
    baseUri: Uri.parse('https://api.example.test'),
    tenantContext: _context,
    appInstanceKey: 'instance-key',
    client: client,
  );
}

Map<String, dynamic> _quote() => {
      'data': {
        'cart_id': 'cart-1',
        'currency_code': 'SAR',
        'inventory_reserved_until': '2026-09-18T12:05:00+00:00',
        'quote': {
          'quoted_at': '2026-09-18T12:00:00+00:00',
          'expires_at': '2026-09-18T12:05:00+00:00',
          'subtotal_minor': 2239,
          'discount_minor': 0,
          'tax_minor': 336,
          'shipping_minor': 0,
          'total_minor': 2575,
          'lines': [],
        },
      },
    };

Map<String, dynamic> _order() => {
      'data': {
        'order': {
          'id': 'order-1',
          'status': 'pending',
          'currency_code': 'SAR',
          'total_minor': 2575,
        },
      },
    };

Map<String, dynamic> _attempt() => {
      'data': {
        'payment': {
          'id': 'payment-1',
          'status': 'pending',
          'currency_code': 'SAR',
          'amount_minor': 2575,
        },
        'attempt': {
          'id': 'attempt-1',
          'status': 'created',
          'provider_code': 'sandbox',
          'method_code': 'card',
          'currency_code': 'SAR',
          'amount_minor': 2575,
          'provider_reference': null,
        },
      },
    };

Map<String, dynamic> _settlement({
  String scenario = 'success',
}) {
  final succeeded = scenario == 'success';
  final attemptStatus = switch (scenario) {
    'success' => 'succeeded',
    'decline' => 'failed',
    'cancel' => 'cancelled',
    _ => 'created',
  };

  return {
    'data': {
      'scenario': scenario,
      'order': {
        'id': 'order-1',
        'status': succeeded ? 'confirmed' : 'pending',
        'currency_code': 'SAR',
        'total_minor': 2575,
      },
      'payment': {
        'id': 'payment-1',
        'status': succeeded ? 'paid' : 'pending',
        'currency_code': 'SAR',
        'amount_minor': 2575,
      },
      'attempt': {
        'id': 'attempt-1',
        'status': attemptStatus,
        'provider_code': 'sandbox',
        'method_code': 'card',
        'currency_code': 'SAR',
        'amount_minor': 2575,
        'provider_reference': 'sandbox-attempt-1',
      },
    },
  };
}

Future<StorefrontCheckoutData> _data(
  http.Client client, {
  required _MemoryStorage storage,
  int seed = 1,
}) async {
  final sessions = CartSessionStore(
    storage: storage,
    random: Random(seed),
  );

  await sessions.saveSession(
    _context,
    const CartSession(
      cartId: 'cart-1',
      token: 'cart-token-1',
    ),
  );

  return StorefrontCheckoutData(
    apiClient: _api(client),
    tenantContext: _context,
    sessionStore: sessions,
  );
}

void main() {
  test('quote and order use secure cart credential', () async {
    final requests = <http.Request>[];

    final mock = MockClient((request) async {
      requests.add(request);

      if (request.url.path.endsWith('/checkout/quote')) {
        return http.Response(
          jsonEncode(_quote()),
          200,
          headers: {'content-type': 'application/json'},
        );
      }

      if (request.url.path.endsWith('/checkout/order')) {
        return http.Response(
          jsonEncode(_order()),
          201,
          headers: {'content-type': 'application/json'},
        );
      }

      return http.Response('not found', 404);
    });

    final storage = _MemoryStorage();
    final data = await _data(
      mock,
      storage: storage,
    );

    final quote = await data.quote();
    final order = await data.createOrder(
      customerName: 'Sandbox Buyer',
    );

    expect(quote.totalMinor, 2575);
    expect(order.totalMinor, 2575);
    expect(requests, hasLength(2));

    for (final request in requests) {
      expect(
        request.headers['X-Cart-Token'],
        'cart-token-1',
      );
      expect(
        request.headers['X-Branch-Id'],
        '10',
      );
    }
  });

  test('payment retry reuses idempotency key after network loss', () async {
    final keys = <String?>[];
    var attempts = 0;

    final mock = MockClient((request) async {
      if (!request.url.path.endsWith('/checkout/payment-attempts')) {
        return http.Response('not found', 404);
      }

      attempts += 1;
      keys.add(
        request.headers['Idempotency-Key'],
      );

      if (attempts == 1) {
        throw http.ClientException(
          'simulated lost connection',
        );
      }

      return http.Response(
        jsonEncode(_attempt()),
        201,
        headers: {'content-type': 'application/json'},
      );
    });

    final storage = _MemoryStorage();
    final data = await _data(
      mock,
      storage: storage,
      seed: 2,
    );

    await expectLater(
      () => data.createPaymentAttempt(
        providerCode: 'sandbox',
        methodCode: 'card',
      ),
      throwsA(isA<ApiNetworkException>()),
    );

    final result = await data.createPaymentAttempt(
      providerCode: 'sandbox',
      methodCode: 'card',
    );

    expect(result.amountMinor, 2575);
    expect(result.attemptStatus, 'created');
    expect(keys, hasLength(2));
    expect(keys.first, isNotEmpty);
    expect(keys.last, keys.first);
  });

  test('sandbox settlement sends scenario only with cart credential', () async {
    http.Request? captured;

    final mock = MockClient((request) async {
      captured = request;

      return http.Response(
        jsonEncode(_settlement()),
        200,
        headers: {'content-type': 'application/json'},
      );
    });

    final storage = _MemoryStorage();
    final data = await _data(
      mock,
      storage: storage,
      seed: 4,
    );

    final result = await data.settleSandboxPayment(
      attemptId: 'attempt-1',
      scenario: 'success',
    );

    expect(result.succeeded, isTrue);
    expect(result.order.status, 'confirmed');
    expect(result.paymentAttempt.paymentStatus, 'paid');
    expect(
      captured!.url.path,
      '/api/v1/storefront/carts/cart-1/checkout/payment-attempts/'
      'attempt-1/sandbox/settle',
    );
    expect(
      captured!.headers['X-Cart-Token'],
      'cart-token-1',
    );

    final body = jsonDecode(captured!.body) as Map<String, dynamic>;

    expect(body, {'scenario': 'success'});
    expect(body.containsKey('amount_minor'), isFalse);
    expect(body.containsKey('currency_code'), isFalse);
    expect(body.containsKey('card_number'), isFalse);

    final session = await CartSessionStore(
      storage: storage,
      random: Random(40),
    ).readSession(_context);

    expect(session, isNull);
  });

  test('successful settlement remains successful if session cleanup fails',
      () async {
    final mock = MockClient((request) async {
      return http.Response(
        jsonEncode(_settlement()),
        200,
        headers: {'content-type': 'application/json'},
      );
    });

    final storage = _MemoryStorage(
      throwOnDelete: true,
    );
    final data = await _data(
      mock,
      storage: storage,
      seed: 43,
    );

    final result = await data.settleSandboxPayment(
      attemptId: 'attempt-1',
      scenario: 'success',
    );

    expect(result.succeeded, isTrue);

    final session = await CartSessionStore(
      storage: storage,
      random: Random(44),
    ).readSession(_context);

    expect(session?.cartId, 'cart-1');
  });

  test('declined sandbox settlement keeps cart session for retry', () async {
    final mock = MockClient((request) async {
      return http.Response(
        jsonEncode(
          _settlement(
            scenario: 'decline',
          ),
        ),
        200,
        headers: {'content-type': 'application/json'},
      );
    });

    final storage = _MemoryStorage();
    final data = await _data(
      mock,
      storage: storage,
      seed: 41,
    );

    final result = await data.settleSandboxPayment(
      attemptId: 'attempt-1',
      scenario: 'decline',
    );

    expect(result.declined, isTrue);

    final session = await CartSessionStore(
      storage: storage,
      random: Random(42),
    ).readSession(_context);

    expect(session?.cartId, 'cart-1');
    expect(session?.token, 'cart-token-1');
  });

  test('sandbox settlement rejects unsupported scenario before network',
      () async {
    var requests = 0;

    final mock = MockClient((request) async {
      requests += 1;
      return http.Response('not found', 404);
    });

    final data = await _data(
      mock,
      storage: _MemoryStorage(),
      seed: 5,
    );

    await expectLater(
      () => data.settleSandboxPayment(
        attemptId: 'attempt-1',
        scenario: 'production-charge',
      ),
      throwsA(isA<ArgumentError>()),
    );

    expect(requests, 0);
  });

  test('checkout fails closed without a cart session', () async {
    final mock = MockClient(
      (_) async => http.Response('not found', 404),
    );

    final data = StorefrontCheckoutData(
      apiClient: _api(mock),
      tenantContext: _context,
      sessionStore: CartSessionStore(
        storage: _MemoryStorage(),
        random: Random(3),
      ),
    );

    await expectLater(
      data.quote,
      throwsA(isA<StateError>()),
    );
  });
}
