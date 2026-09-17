import 'dart:convert';

import 'package:ecommerce_app/core/network/api_client.dart';
import 'package:ecommerce_app/core/platform/tenant_context.dart';
import 'package:ecommerce_app/data/datasource/remote/home_data.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

ApiClient buildApi(
  MockClient client,
) {
  return ApiClient(
    baseUri: Uri.parse('https://api.example.test'),
    tenantContext: const TenantContext(
      tenantId: 'tenant-1',
      branchId: 'branch-9',
    ),
    appInstanceKey: 'instance-key',
    client: client,
  );
}

Map<String, dynamic> storefrontEnvelope() {
  return <String, dynamic>{
    'data': <String, dynamic>{
      'branch': <String, dynamic>{
        'id': 'branch-9',
      },
      'items': <Map<String, dynamic>>[
        <String, dynamic>{
          'product_id': '7',
          'sku_id': '11',
          'code': 'SKU-11',
          'barcode': '6280000000011',
          'name_ar': 'منتج',
          'name_en': 'Product',
          'description_ar': 'وصف',
          'description_en': 'Description',
          'image_url': null,
          'price': <String, dynamic>{
            'amount_minor': 2575,
            'currency_code': 'SAR',
            'tax_rate_bps': 1500,
            'tax_inclusive': true,
          },
          'availability': <String, dynamic>{
            'tracked': true,
            'available_to_sell': 4,
            'in_stock': true,
          },
        },
      ],
      'pagination': <String, dynamic>{
        'current_page': 1,
        'per_page': 16,
        'has_more': false,
      },
    },
  };
}

void main() {
  test('home uses Laravel storefront and adapts price and ATS', () async {
    late http.Request captured;
    final mock = MockClient((request) async {
      captured = request;
      return http.Response(
        jsonEncode(storefrontEnvelope()),
        200,
        headers: {'content-type': 'application/json'},
      );
    });

    final api = buildApi(mock);
    addTearDown(api.close);

    final data = HomeData(apiClient: api);
    final response = await data.getData();

    expect(captured.method, 'GET');
    expect(captured.url.path, '/api/v1/storefront/home');
    expect(captured.headers['X-App-Instance-Key'], 'instance-key');
    expect(captured.headers['X-Branch-Id'], 'branch-9');

    expect(response['status'], 'success');
    expect(response['categories']['data'], isEmpty);

    final item = response['items']['data'].single;
    expect(item['platform_managed'], isTrue);
    expect(item['platform_product_id'], '7');
    expect(item['platform_sku_id'], '11');
    expect(item['platform_sku_code'], 'SKU-11');
    expect(item['items_price'], 25.75);
    expect(item['currency_code'], 'SAR');
    expect(item['available_to_sell'], 4);
    expect(item['items_active'], 1);
  });

  test('search uses storefront products query and adapts results', () async {
    late http.Request captured;
    final mock = MockClient((request) async {
      captured = request;
      return http.Response(
        jsonEncode(storefrontEnvelope()),
        200,
        headers: {'content-type': 'application/json'},
      );
    });

    final api = buildApi(mock);
    addTearDown(api.close);

    final data = HomeData(apiClient: api);
    final response = await data.searchData('  vitamin c  ');

    expect(captured.method, 'GET');
    expect(captured.url.path, '/api/v1/storefront/products');
    expect(captured.url.queryParameters['q'], 'vitamin c');
    expect(response['status'], 'success');
    expect(response['data'], hasLength(1));
    expect(response['data'].single['platform_sku_id'], '11');
  });
}
