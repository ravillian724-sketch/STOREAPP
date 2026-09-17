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
