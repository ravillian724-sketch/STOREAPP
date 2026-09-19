import 'dart:convert';
import 'dart:math';

import 'package:ecommerce_app/core/customer/customer_session_store.dart';
import 'package:ecommerce_app/core/network/api_client.dart';
import 'package:ecommerce_app/core/order/order_access_store.dart';
import 'package:ecommerce_app/core/platform/tenant_context.dart';
import 'package:ecommerce_app/data/datasource/remote/storefront_order_data.dart';
import 'package:ecommerce_app/data/model/storefront_customer_model.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

class _MemoryStorage implements OrderSecureStorage, CustomerSecureStorage {
  final Map<String, String> values = {};

  @override
  Future<void> delete(String key) async {
    values.remove(key);
  }

  @override
  Future<String?> read(String key) async {
    return values[key];
  }

  @override
  Future<void> write(
    String key,
    String value,
  ) async {
    values[key] = value;
  }
}

const _context = TenantContext(
  tenantId: 'tenant-1',
  brandId: 'brand-1',
  branchId: '10',
);
ApiClient _api(
  http.Client client,
) {
  return ApiClient(
    baseUri: Uri.parse(
      'https://api.example.test',
    ),
    tenantContext: _context,
    appInstanceKey: 'instance-key',
    client: client,
  );
}

Map<String, dynamic> _payload() {
  return {
    'data': {
      'order': {
        'id': 'order-1',
        'status': 'confirmed',
        'currency_code': 'SAR',
        'subtotal_minor': 2239,
        'discount_minor': 0,
        'tax_minor': 336,
        'shipping_minor': 0,
        'total_minor': 2575,
        'customer_name': 'Guest Buyer',
        'created_at': '2026-09-19T00:00:00+00:00',
        'confirmed_at': '2026-09-19T00:01:00+00:00',
        'items': [
          {
            'id': 'item-1',
            'sku_code': 'DEMO-WEB-001',
            'product_name_ar': 'مسكن تجريبي',
            'product_name_en': 'Demo Pain Relief',
            'quantity': 1,
            'unit_net_minor': 2239,
            'tax_minor': 336,
            'line_total_minor': 2575,
          },
        ],
      },
      'payment': {
        'id': 'payment-1',
        'status': 'paid',
        'currency_code': 'SAR',
        'amount_minor': 2575,
      },
    },
  };
}

Map<String, dynamic> _orderData({
  required String id,
  required String createdAt,
}) {
  final payload = _payload();
  final data = Map<String, dynamic>.from(
    payload['data'] as Map,
  );
  final order = Map<String, dynamic>.from(
    data['order'] as Map,
  );

  order['id'] = id;
  order['created_at'] = createdAt;
  data['order'] = order;

  return data;
}

Future<OrderAccessStore> _access(
  _MemoryStorage storage,
) async {
  final store = OrderAccessStore(
    storage: storage,
    random: Random(9),
  );
  final token = await store.checkoutToken(
    _context,
    cartId: 'cart-1',
  );
  await store.saveOrder(
    _context,
    cartId: 'cart-1',
    orderId: 'order-1',
    token: token,
  );

  return store;
}

void main() {
  test('retrieves remembered order with secure order credential', () async {
    http.Request? captured;
    final storage = _MemoryStorage();
    final access = await _access(storage);

    final mock = MockClient((request) async {
      captured = request;

      return http.Response(
        jsonEncode(_payload()),
        200,
        headers: {
          'content-type': 'application/json',
        },
      );
    });

    final data = StorefrontOrderData(
      apiClient: _api(mock),
      tenantContext: _context,
      orderAccessStore: access,
      customerSessionStore: CustomerSessionStore(
        storage: storage,
      ),
    );

    final order = await data.getOrder(
      'order-1',
    );

    expect(order.status, 'confirmed');
    expect(order.totalMinor, 2575);
    expect(order.items.single.productNameEn, 'Demo Pain Relief');
    expect(order.payment?.status, 'paid');
    expect(
      captured!.url.path,
      '/api/v1/storefront/orders/order-1',
    );
    expect(
      captured!.headers['X-Order-Token'],
      await access.readOrderToken(
        _context,
        'order-1',
      ),
    );
    expect(
      captured!.headers['X-App-Instance-Key'],
      'instance-key',
    );
  });

  test('fails closed before network when order credential is missing',
      () async {
    var requests = 0;
    final storage = _MemoryStorage();

    final mock = MockClient((request) async {
      requests += 1;
      return http.Response('not found', 404);
    });

    final data = StorefrontOrderData(
      apiClient: _api(mock),
      tenantContext: _context,
      orderAccessStore: OrderAccessStore(
        storage: storage,
        random: Random(10),
      ),
      customerSessionStore: CustomerSessionStore(
        storage: storage,
      ),
    );

    await expectLater(
      data.getOrder('order-missing'),
      throwsA(isA<StateError>()),
    );

    expect(requests, 0);
  });

  test('remembered order list prunes stale 404 receipts and continues',
      () async {
    final storage = _MemoryStorage();
    final access = await _access(storage);

    final staleToken = await access.checkoutToken(
      _context,
      cartId: 'cart-stale',
    );
    await access.saveOrder(
      _context,
      cartId: 'cart-stale',
      orderId: 'order-stale',
      token: staleToken,
    );

    final requested = <String>[];
    final mock = MockClient((request) async {
      requested.add(request.url.path);

      if (request.url.path.endsWith('/order-stale')) {
        return http.Response(
          jsonEncode({
            'error': {
              'code': 'ORDER_NOT_FOUND',
              'message': 'Order was not found.',
            }
          }),
          404,
          headers: {
            'content-type': 'application/json',
          },
        );
      }

      return http.Response(
        jsonEncode(_payload()),
        200,
        headers: {
          'content-type': 'application/json',
        },
      );
    });

    final data = StorefrontOrderData(
      apiClient: _api(mock),
      tenantContext: _context,
      orderAccessStore: access,
      customerSessionStore: CustomerSessionStore(
        storage: storage,
      ),
    );

    final orders = await data.getRememberedOrders();

    expect(
      requested,
      [
        '/api/v1/storefront/orders/order-stale',
        '/api/v1/storefront/orders/order-1',
      ],
    );
    expect(orders.map((order) => order.id).toList(), ['order-1']);
    expect(
      await access.readOrderToken(
        _context,
        'order-stale',
      ),
      isNull,
    );
    expect(
      await access.listOrderIds(_context),
      ['order-1'],
    );
  });

  test('account history paginates and merges guest receipts without duplicates',
      () async {
    final storage = _MemoryStorage();
    final access = await _access(storage);
    final customers = CustomerSessionStore(
      storage: storage,
    );

    await customers.saveSession(
      _context,
      StorefrontCustomerSession(
        accessToken: 'customer-token-1',
        expiresAt: DateTime.now().toUtc().add(const Duration(days: 1)),
        customer: const StorefrontCustomer(
          id: 'customer-1',
          name: 'Buyer',
          email: 'buyer@example.com',
          emailVerified: false,
        ),
      ),
    );

    final requests = <http.Request>[];

    final mock = MockClient((request) async {
      requests.add(request);

      if (request.url.path == '/api/v1/storefront/customer/orders') {
        final page = request.url.queryParameters['page'];
        final data = page == '1'
            ? _orderData(
                id: 'order-2',
                createdAt: '2026-09-20T00:00:00+00:00',
              )
            : _orderData(
                id: 'order-1',
                createdAt: '2026-09-19T00:00:00+00:00',
              );

        return http.Response(
          jsonEncode({
            'data': {
              'orders': [data],
              'pagination': {
                'page': int.parse(page!),
                'per_page': 50,
                'total': 2,
                'last_page': 2,
              },
            },
          }),
          200,
          headers: {
            'content-type': 'application/json',
          },
        );
      }

      if (request.url.path.endsWith('/order-1')) {
        return http.Response(
          jsonEncode(_payload()),
          200,
          headers: {
            'content-type': 'application/json',
          },
        );
      }

      return http.Response('not found', 404);
    });

    final data = StorefrontOrderData(
      apiClient: _api(mock),
      tenantContext: _context,
      orderAccessStore: access,
      customerSessionStore: customers,
    );

    final orders = await data.getRememberedOrders();

    expect(
      orders.map((order) => order.id).toList(),
      ['order-2', 'order-1'],
    );

    final accountRequests = requests.where(
      (request) => request.url.path == '/api/v1/storefront/customer/orders',
    );

    expect(accountRequests, hasLength(2));

    for (final request in accountRequests) {
      expect(
        request.headers['Authorization'],
        'Bearer customer-token-1',
      );
      expect(
        request.url.queryParameters['per_page'],
        '50',
      );
    }

    expect(
      requests.where((request) => request.url.path.endsWith('/order-1')),
      hasLength(1),
    );
  });

  test('unauthorized account history clears expired server session', () async {
    final storage = _MemoryStorage();
    final customers = CustomerSessionStore(
      storage: storage,
    );

    await customers.saveSession(
      _context,
      StorefrontCustomerSession(
        accessToken: 'revoked-token',
        expiresAt: DateTime.now().toUtc().add(const Duration(days: 1)),
        customer: const StorefrontCustomer(
          id: 'customer-1',
          name: 'Buyer',
          email: 'buyer@example.com',
          emailVerified: false,
        ),
      ),
    );

    final mock = MockClient((request) async {
      return http.Response(
        jsonEncode({
          'error': {
            'code': 'UNAUTHENTICATED',
            'message': 'Authentication is required.',
          },
        }),
        401,
        headers: {
          'content-type': 'application/json',
        },
      );
    });

    final api = _api(mock);
    addTearDown(api.close);

    final data = StorefrontOrderData(
      apiClient: api,
      tenantContext: _context,
      orderAccessStore: OrderAccessStore(
        storage: storage,
      ),
      customerSessionStore: customers,
    );

    await expectLater(
      data.getRememberedOrders(),
      throwsA(isA<Exception>()),
    );

    expect(
      await customers.readSession(_context),
      isNull,
    );
  });
}
