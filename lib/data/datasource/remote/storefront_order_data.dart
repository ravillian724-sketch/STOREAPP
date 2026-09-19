import 'package:ecommerce_app/core/customer/customer_session_store.dart';
import 'package:ecommerce_app/core/network/api_client.dart';
import 'package:ecommerce_app/core/network/api_exception.dart';
import 'package:ecommerce_app/core/order/order_access_store.dart';
import 'package:ecommerce_app/core/platform/platform_service.dart';
import 'package:ecommerce_app/core/platform/tenant_context.dart';
import 'package:ecommerce_app/data/model/storefront_order_model.dart';

class StorefrontOrderData {
  StorefrontOrderData({
    ApiClient? apiClient,
    TenantContext? tenantContext,
    OrderAccessStore? orderAccessStore,
    CustomerSessionStore? customerSessionStore,
  })  : _apiClient = apiClient,
        _tenantContext = tenantContext,
        _orders = orderAccessStore ?? OrderAccessStore(),
        _customers = customerSessionStore ?? CustomerSessionStore();

  final ApiClient? _apiClient;
  final TenantContext? _tenantContext;
  final OrderAccessStore _orders;
  final CustomerSessionStore _customers;

  ApiClient get _client {
    final client = _apiClient ?? PlatformService.instance.apiClient;

    if (client == null) {
      throw StateError(
        'PlatformService must be initialized before order requests.',
      );
    }

    return client;
  }

  TenantContext get _context {
    final context = _tenantContext ?? PlatformService.instance.tenantContext;

    if (context == null) {
      throw StateError(
        'Tenant context is required for order requests.',
      );
    }

    return context;
  }

  Future<StorefrontOrderDetails> getOrder(
    String orderId,
  ) async {
    final normalizedOrderId = orderId.trim();

    if (normalizedOrderId.isEmpty) {
      throw ArgumentError.value(
        orderId,
        'orderId',
        'Order id must not be empty.',
      );
    }

    final token = await _orders.readOrderToken(
      _context,
      normalizedOrderId,
    );

    if (token == null) {
      throw StateError(
        'Secure order access is unavailable on this device.',
      );
    }
    return _fetchOrder(
      normalizedOrderId,
      token,
    );
  }

  Future<List<StorefrontOrderDetails>> getRememberedOrders() async {
    final context = _context;
    final results = <StorefrontOrderDetails>[
      ...await _customerOrders(context),
    ];
    final ids = await _orders.listOrderIds(
      context,
    );

    for (final id in ids) {
      final token = await _orders.readOrderToken(
        context,
        id,
      );

      if (token == null) {
        await _orders.removeOrder(
          context,
          id,
        );
        continue;
      }

      try {
        results.add(
          await _fetchOrder(
            id,
            token,
          ),
        );
      } on ApiException catch (error) {
        if (error.statusCode != 404) {
          rethrow;
        }

        await _orders.removeOrder(
          context,
          id,
        );
      }
    }

    final unique = <String, StorefrontOrderDetails>{};

    for (final order in results) {
      unique.putIfAbsent(
        order.id,
        () => order,
      );
    }

    final merged = unique.values.toList(growable: false);

    merged.sort((a, b) {
      final aDate = a.createdAt;
      final bDate = b.createdAt;

      if (aDate == null && bDate == null) {
        return b.id.compareTo(a.id);
      }
      if (aDate == null) {
        return 1;
      }
      if (bDate == null) {
        return -1;
      }

      return bDate.compareTo(aDate);
    });

    return merged;
  }

  Future<List<StorefrontOrderDetails>> _customerOrders(
    TenantContext context,
  ) async {
    final session = await _customers.readSession(context);

    if (session == null) {
      return const [];
    }

    final results = <StorefrontOrderDetails>[];
    var page = 1;
    var lastPage = 1;

    try {
      do {
        final response = await _client.get(
          '/api/v1/storefront/customer/orders',
          accessToken: session.accessToken,
          queryParameters: {
            'page': '$page',
            'per_page': '50',
          },
        );

        final data = _data(response.data);
        final rawOrders = data['orders'];

        if (rawOrders is! List) {
          throw const FormatException(
            'Customer order history is missing orders.',
          );
        }

        for (final raw in rawOrders) {
          if (raw is! Map) {
            throw const FormatException(
              'Customer order history contains an invalid order.',
            );
          }

          results.add(
            StorefrontOrderDetails.fromJson(
              Map<String, dynamic>.from(raw),
            ),
          );
        }

        final pagination = data['pagination'];

        if (pagination is Map) {
          final normalized = Map<String, dynamic>.from(pagination);
          lastPage = int.tryParse(
                normalized['last_page']?.toString() ?? '',
              ) ??
              page;
        } else {
          lastPage = page;
        }

        page += 1;
      } while (page <= lastPage);
    } on ApiException catch (error) {
      if (error.statusCode == 401) {
        await _customers.clearSession(context);
      }

      rethrow;
    }

    return results;
  }

  Future<StorefrontOrderDetails> _fetchOrder(
    String orderId,
    String token,
  ) async {
    final response = await _client.get(
      '/api/v1/storefront/orders/$orderId',
      extraHeaders: {
        'X-Order-Token': token,
      },
    );

    return StorefrontOrderDetails.fromJson(
      _data(response.data),
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

    return Map<String, dynamic>.from(data);
  }
}
