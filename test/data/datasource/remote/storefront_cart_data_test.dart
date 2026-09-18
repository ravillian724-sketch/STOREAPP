import 'dart:convert';
import 'dart:math';

import 'package:ecommerce_app/core/cart/cart_session.dart';
import 'package:ecommerce_app/core/cart/cart_session_store.dart';
import 'package:ecommerce_app/core/network/api_client.dart';
import 'package:ecommerce_app/core/network/api_exception.dart';
import 'package:ecommerce_app/core/platform/tenant_context.dart';
import 'package:ecommerce_app/data/datasource/remote/storefront_cart_data.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

class _MemoryStorage implements CartSecureStorage {
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

Map<String, dynamic> _cartPayload({
  String cartId = 'cart-1',
  String? cartToken,
  int quantity = 0,
}) {
  final items = quantity == 0
      ? <Map<String, dynamic>>[]
      : <Map<String, dynamic>>[
          {
            'cart_item_id': 'item-1',
            'product_id': '7',
            'sku_id': '11',
            'code': 'SKU-11',
            'barcode': '6280000000011',
            'name_ar': 'منتج',
            'name_en': 'Product',
            'description_ar': 'وصف',
            'description_en': 'Description',
            'image_url': null,
            'quantity': quantity,
            'max_quantity': 9,
            'pricing': {
              'currency_code': 'SAR',
              'catalog_unit_amount_minor': 2575,
              'display_unit_amount_minor': 2575,
              'tax_inclusive': true,
              'unit_net_minor': 2239,
              'line_subtotal_minor': 2239 * quantity,
              'discount_minor': 0,
              'tax_rate_bps': 1500,
              'tax_minor': 336 * quantity,
              'line_total_minor': 2575 * quantity,
            },
          },
        ];

  final data = <String, dynamic>{
    'cart': {
      'id': cartId,
      'status': 'active',
      'expires_at': '2026-10-18T00:00:00+00:00',
      'inventory_reserved_until': null,
    },
    'branch': {
      'id': '10',
      'code': 'MAIN',
      'name_ar': 'الرئيسي',
      'name_en': 'Main',
    },
    'currency_code': 'SAR',
    'items': items,
    'totals': {
      'subtotal_minor': 2239 * quantity,
      'discount_minor': 0,
      'tax_minor': 336 * quantity,
      'shipping_minor': 0,
      'total_minor': 2575 * quantity,
      'quoted_at': quantity == 0 ? null : '2026-09-18T00:00:00+00:00',
      'quote_expires_at': quantity == 0 ? null : '2026-09-18T00:05:00+00:00',
    },
  };

  if (cartToken != null) {
    data['cart_token'] = cartToken;
  }

  return {'data': data};
}

ApiClient _api(
  http.Client client,
) {
  return ApiClient(
    baseUri: Uri.parse('https://api.example.test'),
    tenantContext: const TenantContext(
      tenantId: 'tenant-1',
      branchId: '10',
    ),
    appInstanceKey: 'instance-key',
    client: client,
  );
}

void main() {
  test('load creates a guest cart then reuses secure session', () async {
    final requests = <http.Request>[];
    final mock = MockClient((request) async {
      requests.add(request);

      if (request.method == 'POST' &&
          request.url.path == '/api/v1/storefront/carts') {
        return http.Response(
          jsonEncode(
            _cartPayload(
              cartToken: 'cart-token-1',
            ),
          ),
          201,
          headers: {
            'content-type': 'application/json',
          },
        );
      }

      if (request.method == 'GET' &&
          request.url.path == '/api/v1/storefront/carts/cart-1') {
        return http.Response(
          jsonEncode(_cartPayload()),
          200,
          headers: {
            'content-type': 'application/json',
          },
        );
      }

      return http.Response('not found', 404);
    });

    final api = _api(mock);
    addTearDown(api.close);

    final storage = _MemoryStorage();
    final sessions = CartSessionStore(
      storage: storage,
      random: Random(1),
    );

    final data = StorefrontCartData(
      apiClient: api,
      tenantContext: const TenantContext(
        tenantId: 'tenant-1',
        branchId: '10',
      ),
      sessionStore: sessions,
    );

    final created = await data.load();
    final loaded = await data.load();

    expect(created.cartId, 'cart-1');
    expect(loaded.cartId, 'cart-1');
    expect(requests, hasLength(2));

    expect(requests.first.method, 'POST');
    expect(
      requests.first.headers['Idempotency-Key'],
      isNotEmpty,
    );

    expect(requests.last.method, 'GET');
    expect(
      requests.last.headers['X-Cart-Token'],
      'cart-token-1',
    );
  });

  test('add retry reuses same idempotency key after network loss', () async {
    final addKeys = <String?>[];
    var addAttempts = 0;

    final mock = MockClient((request) async {
      if (request.method == 'POST' &&
          request.url.path == '/api/v1/storefront/carts') {
        return http.Response(
          jsonEncode(
            _cartPayload(
              cartToken: 'cart-token-1',
            ),
          ),
          201,
          headers: {
            'content-type': 'application/json',
          },
        );
      }

      if (request.method == 'POST' &&
          request.url.path == '/api/v1/storefront/carts/cart-1/items') {
        addAttempts += 1;
        addKeys.add(
          request.headers['Idempotency-Key'],
        );

        if (addAttempts == 1) {
          throw http.ClientException(
            'simulated lost connection',
          );
        }

        return http.Response(
          jsonEncode(
            _cartPayload(quantity: 1),
          ),
          200,
          headers: {
            'content-type': 'application/json',
          },
        );
      }

      return http.Response('not found', 404);
    });

    final api = _api(mock);
    addTearDown(api.close);

    final storage = _MemoryStorage();
    final sessions = CartSessionStore(
      storage: storage,
      random: Random(2),
    );

    final data = StorefrontCartData(
      apiClient: api,
      tenantContext: const TenantContext(
        tenantId: 'tenant-1',
        branchId: '10',
      ),
      sessionStore: sessions,
    );

    await data.load();

    await expectLater(
      () => data.addSku(
        skuId: '11',
      ),
      throwsA(
        isA<ApiNetworkException>(),
      ),
    );

    final recovered = await data.addSku(
      skuId: '11',
    );

    expect(recovered.items, hasLength(1));
    expect(recovered.items.single.quantity, 1);
    expect(addKeys, hasLength(2));
    expect(addKeys.first, isNotNull);
    expect(addKeys.last, addKeys.first);
  });

  test('stale cart session is replaced only for CART_NOT_FOUND', () async {
    var createdCount = 0;

    final mock = MockClient((request) async {
      if (request.method == 'GET') {
        return http.Response(
          jsonEncode({
            'error': {
              'code': 'CART_NOT_FOUND',
              'message': 'Cart was not found.',
            }
          }),
          404,
          headers: {
            'content-type': 'application/json',
          },
        );
      }

      if (request.method == 'POST' &&
          request.url.path == '/api/v1/storefront/carts') {
        createdCount += 1;

        return http.Response(
          jsonEncode(
            _cartPayload(
              cartId: 'cart-2',
              cartToken: 'cart-token-2',
            ),
          ),
          201,
          headers: {
            'content-type': 'application/json',
          },
        );
      }

      return http.Response('not found', 404);
    });

    final api = _api(mock);
    addTearDown(api.close);

    final storage = _MemoryStorage();
    final sessions = CartSessionStore(
      storage: storage,
      random: Random(3),
    );

    const context = TenantContext(
      tenantId: 'tenant-1',
      branchId: '10',
    );

    await sessions.saveSession(
      context,
      const CartSession(
        cartId: 'stale-cart',
        token: 'stale-token',
      ),
    );

    final data = StorefrontCartData(
      apiClient: api,
      tenantContext: context,
      sessionStore: sessions,
    );

    final recovered = await data.load();

    expect(recovered.cartId, 'cart-2');
    expect(createdCount, 1);

    final session = await sessions.readSession(
      context,
    );

    expect(session?.cartId, 'cart-2');
    expect(session?.token, 'cart-token-2');
  });
}
