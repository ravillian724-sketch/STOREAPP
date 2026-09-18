import 'dart:convert';

import 'package:ecommerce_app/core/network/api_client.dart';
import 'package:ecommerce_app/core/platform/tenant_context.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test('sends app instance and tenant boundary headers on every request',
      () async {
    late http.Request captured;
    final client = MockClient((request) async {
      captured = request;
      return http.Response(
          jsonEncode({
            'data': {'ok': true}
          }),
          200);
    });

    final api = ApiClient(
      baseUri: Uri.parse('https://api.example.test/platform'),
      tenantContext: const TenantContext(
        tenantId: 'tenant-7',
        brandId: 'brand-2',
        branchId: 'branch-3',
      ),
      appInstanceKey: 'instance-secret',
      client: client,
    );
    addTearDown(api.close);

    await api.get('/api/v1/branches');

    expect(captured.url.toString(),
        'https://api.example.test/platform/api/v1/branches');
    expect(captured.headers['X-App-Instance-Key'], 'instance-secret');
    expect(captured.headers['X-Tenant-Id'], 'tenant-7');
    expect(captured.headers['X-Brand-Id'], 'brand-2');
    expect(captured.headers['X-Branch-Id'], 'branch-3');
  });

  test('supports request-scoped headers and patch without leaking them',
      () async {
    final captured = <http.Request>[];
    final client = MockClient((request) async {
      captured.add(request);
      return http.Response(
        jsonEncode({
          'data': {'ok': true}
        }),
        200,
      );
    });

    final api = ApiClient(
      baseUri: Uri.parse('https://api.example.test'),
      tenantContext: const TenantContext(
        tenantId: 'tenant-1',
        branchId: 'branch-1',
      ),
      appInstanceKey: 'instance-secret',
      client: client,
    );
    addTearDown(api.close);

    await api.patch(
      '/api/v1/storefront/carts/cart-1/items/item-1',
      body: const {'quantity': 2},
      extraHeaders: const {
        'X-Cart-Token': 'cart-secret',
        'Idempotency-Key': 'mutation-1',
      },
    );

    await api.get('/api/v1/branches');

    expect(captured, hasLength(2));
    expect(captured.first.method, 'PATCH');
    expect(captured.first.headers['X-Cart-Token'], 'cart-secret');
    expect(captured.first.headers['Idempotency-Key'], 'mutation-1');
    expect(jsonDecode(captured.first.body), {'quantity': 2});

    expect(captured.last.method, 'GET');
    expect(captured.last.headers.containsKey('X-Cart-Token'), isFalse);
    expect(captured.last.headers.containsKey('Idempotency-Key'), isFalse);
  });

  test('request-scoped headers cannot override protected platform headers',
      () async {
    final client = MockClient((request) async {
      return http.Response(
        jsonEncode({
          'data': {'ok': true}
        }),
        200,
      );
    });

    final api = ApiClient(
      baseUri: Uri.parse('https://api.example.test'),
      tenantContext: const TenantContext(
        tenantId: 'tenant-1',
        branchId: 'branch-1',
      ),
      appInstanceKey: 'instance-secret',
      client: client,
    );
    addTearDown(api.close);

    await expectLater(
      () => api.get(
        '/api/v1/branches',
        extraHeaders: const {
          'x-app-instance-key': 'forged-instance',
        },
      ),
      throwsArgumentError,
    );

    await expectLater(
      () => api.get(
        '/api/v1/branches',
        extraHeaders: const {
          'X-BRANCH-ID': '999',
        },
      ),
      throwsArgumentError,
    );
  });

  test('fails closed when app instance key is empty', () {
    expect(
      () => ApiClient(
        baseUri: Uri.parse('https://api.example.test'),
        tenantContext: const TenantContext(tenantId: 'tenant-1'),
        appInstanceKey: '   ',
      ),
      throwsA(isA<StateError>()),
    );
  });
}
