import 'dart:convert';

import 'package:ecommerce_app/core/customer/customer_session_store.dart';
import 'package:ecommerce_app/core/network/api_client.dart';
import 'package:ecommerce_app/core/network/api_exception.dart';
import 'package:ecommerce_app/core/platform/tenant_context.dart';
import 'package:ecommerce_app/data/datasource/remote/storefront_customer_auth_data.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

class _MemoryStorage implements CustomerSecureStorage {
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
  branchId: '10',
);

ApiClient _api(http.Client client) {
  return ApiClient(
    baseUri: Uri.parse(
      'https://api.example.test',
    ),
    tenantContext: _context,
    appInstanceKey: 'instance-key',
    client: client,
  );
}

Map<String, dynamic> _authPayload({
  String token = 'customer-token-1',
}) {
  return {
    'data': {
      'token_type': 'Bearer',
      'access_token': token,
      'expires_at': '2099-09-19T00:00:00+00:00',
      'customer': {
        'id': 'customer-1',
        'name': 'Buyer One',
        'email': 'buyer@example.com',
        'phone': '+966500000001',
        'email_verified': false,
        'created_at': '2026-09-19T00:00:00+00:00',
      },
    },
  };
}

void main() {
  test('login stores bearer credential in secure customer session', () async {
    late http.Request captured;
    final storage = _MemoryStorage();

    final mock = MockClient((request) async {
      captured = request;

      return http.Response(
        jsonEncode(_authPayload()),
        200,
        headers: {
          'content-type': 'application/json',
        },
      );
    });

    final api = _api(mock);
    addTearDown(api.close);

    final data = StorefrontCustomerAuthData(
      apiClient: api,
      tenantContext: _context,
      sessionStore: CustomerSessionStore(
        storage: storage,
      ),
    );

    final session = await data.login(
      email: ' BUYER@example.com ',
      password: 'Buyer1234',
    );

    expect(
      captured.url.path,
      '/api/v1/storefront/customer/auth/login',
    );

    final body = jsonDecode(captured.body) as Map<String, dynamic>;

    expect(body['email'], 'BUYER@example.com');
    expect(body['password'], 'Buyer1234');
    expect(
      captured.headers.containsKey('Authorization'),
      isFalse,
    );
    expect(
      session.accessToken,
      'customer-token-1',
    );
    expect(
      (await data.currentSession())?.customer.id,
      'customer-1',
    );
  });

  test('register sends normalized profile fields and stores session', () async {
    late http.Request captured;
    final storage = _MemoryStorage();

    final mock = MockClient((request) async {
      captured = request;

      return http.Response(
        jsonEncode(_authPayload()),
        201,
        headers: {
          'content-type': 'application/json',
        },
      );
    });

    final api = _api(mock);
    addTearDown(api.close);

    final data = StorefrontCustomerAuthData(
      apiClient: api,
      tenantContext: _context,
      sessionStore: CustomerSessionStore(
        storage: storage,
      ),
    );

    await data.register(
      name: ' Buyer One ',
      email: ' buyer@example.com ',
      phone: ' +966500000001 ',
      password: 'Buyer1234',
    );

    final body = jsonDecode(captured.body) as Map<String, dynamic>;

    expect(
      captured.url.path,
      '/api/v1/storefront/customer/auth/register',
    );
    expect(body['name'], 'Buyer One');
    expect(body['email'], 'buyer@example.com');
    expect(body['phone'], '+966500000001');
    expect(
      await data.currentSession(),
      isNotNull,
    );
  });

  test('me and logout use bearer token and logout clears local session',
      () async {
    final storage = _MemoryStorage();
    final requests = <http.Request>[];

    final mock = MockClient((request) async {
      requests.add(request);

      if (request.url.path.endsWith('/auth/login')) {
        return http.Response(
          jsonEncode(_authPayload()),
          200,
          headers: {
            'content-type': 'application/json',
          },
        );
      }

      if (request.url.path.endsWith('/me')) {
        return http.Response(
          jsonEncode({
            'data': {
              'customer': _authPayload()['data']['customer'],
            },
          }),
          200,
          headers: {
            'content-type': 'application/json',
          },
        );
      }

      if (request.url.path.endsWith('/auth/logout')) {
        return http.Response(
          jsonEncode({
            'data': {
              'logged_out': true,
            },
          }),
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

    final data = StorefrontCustomerAuthData(
      apiClient: api,
      tenantContext: _context,
      sessionStore: CustomerSessionStore(
        storage: storage,
      ),
    );

    await data.login(
      email: 'buyer@example.com',
      password: 'Buyer1234',
    );

    final customer = await data.me();
    expect(customer.id, 'customer-1');

    await data.logout();

    expect(
      await data.currentSession(),
      isNull,
    );

    final protected = requests.where(
      (request) =>
          request.url.path.endsWith('/me') ||
          request.url.path.endsWith('/auth/logout'),
    );

    for (final request in protected) {
      expect(
        request.headers['Authorization'],
        'Bearer customer-token-1',
      );
    }
  });

  test('unauthorized profile refresh clears stale secure session', () async {
    final storage = _MemoryStorage();

    final mock = MockClient((request) async {
      if (request.url.path.endsWith('/auth/login')) {
        return http.Response(
          jsonEncode(_authPayload()),
          200,
          headers: {
            'content-type': 'application/json',
          },
        );
      }

      if (request.url.path.endsWith('/me')) {
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
      }

      return http.Response('not found', 404);
    });

    final api = _api(mock);
    addTearDown(api.close);

    final data = StorefrontCustomerAuthData(
      apiClient: api,
      tenantContext: _context,
      sessionStore: CustomerSessionStore(
        storage: storage,
      ),
    );

    await data.login(
      email: 'buyer@example.com',
      password: 'Buyer1234',
    );

    await expectLater(
      data.me(),
      throwsA(isA<ApiException>()),
    );

    expect(
      await data.currentSession(),
      isNull,
    );
  });
}
