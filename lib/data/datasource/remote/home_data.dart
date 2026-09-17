import 'package:ecommerce_app/core/class/statusrequest.dart';
import 'package:ecommerce_app/core/network/api_client.dart';
import 'package:ecommerce_app/core/network/api_exception.dart';
import 'package:ecommerce_app/core/platform/platform_service.dart';

class HomeData {
  HomeData({
    ApiClient? apiClient,
  }) : _apiClient = apiClient;

  final ApiClient? _apiClient;

  ApiClient get _client {
    final client = _apiClient ?? PlatformService.instance.apiClient;

    if (client == null) {
      throw StateError(
        'PlatformService must be initialized before storefront requests.',
      );
    }

    return client;
  }

  Future<dynamic> getData() {
    return _load(
      path: '/api/v1/storefront/home',
      homeShape: true,
    );
  }

  Future<dynamic> searchData(String search) {
    final normalized = search.trim();

    if (normalized.isEmpty) {
      return Future.value(<String, dynamic>{
        'status': 'success',
        'data': <Map<String, dynamic>>[],
      });
    }

    return _load(
      path: '/api/v1/storefront/products',
      query: {'q': normalized},
      homeShape: false,
    );
  }

  Future<dynamic> _load({
    required String path,
    required bool homeShape,
    Map<String, String>? query,
  }) async {
    try {
      final response = await _client.get(
        path,
        queryParameters: query,
      );

      return _adaptResponse(
        response.data,
        homeShape: homeShape,
      );
    } on ApiTimeoutException {
      return StatusRequest.serverException;
    } on ApiNetworkException {
      return StatusRequest.offlinefailuer;
    } on ApiException {
      return StatusRequest.serverfailuer;
    } on StateError {
      return StatusRequest.serverException;
    } catch (_) {
      return StatusRequest.serverException;
    }
  }

  Map<String, dynamic> _adaptResponse(
    dynamic rawResponse, {
    required bool homeShape,
  }) {
    if (rawResponse is! Map) {
      throw const FormatException(
        'Storefront response must be a JSON object.',
      );
    }

    final envelope = Map<String, dynamic>.from(rawResponse);
    final rawData = envelope['data'];

    if (rawData is! Map) {
      throw const FormatException(
        'Storefront response does not contain data.',
      );
    }

    final data = Map<String, dynamic>.from(rawData);
    final rawItems = data['items'];

    if (rawItems is! List) {
      throw const FormatException(
        'Storefront response does not contain an items list.',
      );
    }

    final items = rawItems
        .whereType<Map>()
        .map(
          (item) => _adaptItem(
            Map<String, dynamic>.from(item),
          ),
        )
        .toList(growable: false);

    if (!homeShape) {
      return <String, dynamic>{
        'status': 'success',
        'data': items,
      };
    }

    return <String, dynamic>{
      'status': 'success',
      'categories': <String, dynamic>{
        'data': <Map<String, dynamic>>[],
      },
      'items': <String, dynamic>{
        'data': items,
      },
      'settings': <String, dynamic>{
        'data': <Map<String, dynamic>>[],
      },
    };
  }

  Map<String, dynamic> _adaptItem(
    Map<String, dynamic> item,
  ) {
    final rawPrice = item['price'];
    final price = rawPrice is Map
        ? Map<String, dynamic>.from(rawPrice)
        : const <String, dynamic>{};

    final rawAvailability = item['availability'];
    final availability = rawAvailability is Map
        ? Map<String, dynamic>.from(rawAvailability)
        : const <String, dynamic>{};

    final amountMinor = _asInt(price['amount_minor']);
    final productId = item['product_id']?.toString() ?? '';
    final skuId = item['sku_id']?.toString() ?? '';

    return <String, dynamic>{
      'items_id': int.tryParse(productId),
      'items_name': item['name_en']?.toString(),
      'items_name_ar': item['name_ar']?.toString(),
      'items_desc': item['description_en']?.toString(),
      'items_desc_ar': item['description_ar']?.toString(),
      'items_image': item['image_url']?.toString(),
      'items_count': _asNullableInt(
        availability['available_to_sell'],
      ),
      'items_active': availability['in_stock'] == false ? 0 : 1,
      'items_price': amountMinor / 100.0,
      'items_discount': 0,
      'items_date': null,
      'items_cat': null,
      'items_scientificformula': null,
      'items_scientificformula_ar': null,
      'items_prescription': 0,
      'subcategory_id': null,
      'subcategory_name': null,
      'subcategory_name_ar': null,
      'subcategory_image': null,
      'subcategory_datetime': null,
      'category_id': null,
      'favorite': '0',
      'itemspricediscount': amountMinor / 100.0,
      'platform_managed': true,
      'platform_product_id': productId,
      'platform_sku_id': skuId,
      'platform_sku_code': item['code']?.toString(),
      'platform_barcode': item['barcode']?.toString(),
      'currency_code': price['currency_code']?.toString(),
      'available_to_sell': _asNullableInt(
        availability['available_to_sell'],
      ),
    };
  }

  int _asInt(dynamic value) {
    return int.tryParse(value?.toString() ?? '') ?? 0;
  }

  int? _asNullableInt(dynamic value) {
    if (value == null) {
      return null;
    }

    return int.tryParse(value.toString());
  }
}
