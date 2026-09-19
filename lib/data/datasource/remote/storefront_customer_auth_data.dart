import 'package:ecommerce_app/core/customer/customer_session_store.dart';
import 'package:ecommerce_app/core/network/api_client.dart';
import 'package:ecommerce_app/core/network/api_exception.dart';
import 'package:ecommerce_app/core/platform/platform_service.dart';
import 'package:ecommerce_app/core/platform/tenant_context.dart';
import 'package:ecommerce_app/data/model/storefront_customer_model.dart';

class StorefrontCustomerAuthData {
  StorefrontCustomerAuthData({
    ApiClient? apiClient,
    TenantContext? tenantContext,
    CustomerSessionStore? sessionStore,
  })  : _apiClient = apiClient,
        _tenantContext = tenantContext,
        _sessions = sessionStore ?? CustomerSessionStore();

  final ApiClient? _apiClient;
  final TenantContext? _tenantContext;
  final CustomerSessionStore _sessions;

  ApiClient get _client {
    final client = _apiClient ?? PlatformService.instance.apiClient;

    if (client == null) {
      throw StateError(
        'PlatformService must be initialized before customer requests.',
      );
    }

    return client;
  }

  TenantContext get _context {
    final context = _tenantContext ?? PlatformService.instance.tenantContext;

    if (context == null) {
      throw StateError(
        'Tenant context is required for customer requests.',
      );
    }

    return context;
  }

  Future<StorefrontCustomerSession> register({
    required String name,
    required String email,
    required String password,
    String? phone,
    String deviceName = 'storeapp-mobile',
  }) {
    return _authenticate(
      path: '/api/v1/storefront/customer/auth/register',
      body: {
        'name': name.trim(),
        'email': email.trim(),
        'password': password,
        if (phone?.trim().isNotEmpty == true) 'phone': phone!.trim(),
        'device_name': deviceName.trim(),
      },
    );
  }

  Future<StorefrontCustomerSession> login({
    required String email,
    required String password,
    String deviceName = 'storeapp-mobile',
  }) {
    return _authenticate(
      path: '/api/v1/storefront/customer/auth/login',
      body: {
        'email': email.trim(),
        'password': password,
        'device_name': deviceName.trim(),
      },
    );
  }

  Future<StorefrontCustomerSession?> currentSession() {
    return _sessions.readSession(
      _context,
    );
  }

  Future<StorefrontCustomer> me() async {
    final session = await _requiredSession();

    try {
      final response = await _client.get(
        '/api/v1/storefront/customer/me',
        accessToken: session.accessToken,
      );

      final data = _data(response.data);
      final rawCustomer = data['customer'];

      if (rawCustomer is! Map) {
        throw const FormatException(
          'Customer profile response is missing customer data.',
        );
      }

      return StorefrontCustomer.fromJson(
        Map<String, dynamic>.from(rawCustomer),
      );
    } on ApiException catch (error) {
      if (error.statusCode == 401) {
        await _sessions.clearSession(
          _context,
        );
      }

      rethrow;
    }
  }

  Future<void> logout() async {
    final session = await currentSession();

    try {
      if (session != null) {
        await _client.post(
          '/api/v1/storefront/customer/auth/logout',
          accessToken: session.accessToken,
        );
      }
    } finally {
      await _sessions.clearSession(
        _context,
      );
    }
  }

  Future<StorefrontCustomerSession> _authenticate({
    required String path,
    required Map<String, dynamic> body,
  }) async {
    final response = await _client.post(
      path,
      body: body,
    );

    final session = StorefrontCustomerSession.fromJson(
      _data(response.data),
    );

    await _sessions.saveSession(
      _context,
      session,
    );

    return session;
  }

  Future<StorefrontCustomerSession> _requiredSession() async {
    final session = await currentSession();

    if (session == null) {
      throw StateError(
        'Customer authentication is required.',
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
