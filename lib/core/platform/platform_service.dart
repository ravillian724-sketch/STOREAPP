import 'package:ecommerce_app/core/network/api_client.dart';

import 'app_instance_config.dart';
import 'bootstrap_result.dart';
import 'bootstrap_service.dart';
import 'environment_config.dart';
import 'store_config.dart';
import 'tenant_context.dart';

class PlatformService {
  PlatformService._();

  static final PlatformService instance = PlatformService._();

  StoreConfig? _storeConfig;
  TenantContext? _tenantContext;
  ApiClient? _apiClient;

  StoreConfig? get storeConfig => _storeConfig;

  TenantContext? get tenantContext => _tenantContext;

  ApiClient? get apiClient => _apiClient;

  bool get isInitialized =>
      _storeConfig != null && _tenantContext != null && _apiClient != null;

  Future<BootstrapResult> bootstrap({
    BootstrapService? bootstrapService,
  }) async {
    final ownsService = bootstrapService == null;
    final service = bootstrapService ?? BootstrapService();

    try {
      final result = await service.load();

      initialize(
        storeConfig: result.storeConfig,
        branchId: result.defaultBranchId,
      );

      return result;
    } finally {
      if (ownsService) {
        service.close();
      }
    }
  }

  void initialize({
    required StoreConfig storeConfig,
    String? branchId,
  }) {
    _apiClient?.close();

    _storeConfig = storeConfig;

    _tenantContext = TenantContext(
      tenantId: storeConfig.tenantId,
      brandId: storeConfig.brandId,
      branchId: branchId,
    );

    final baseUri = EnvironmentConfig.requireApiBaseUri();

    _apiClient = ApiClient(
      baseUri: baseUri,
      tenantContext: _tenantContext!,
      appInstanceKey: AppInstanceConfig.instanceKey,
    );
  }

  void selectBranch(String? branchId) {
    final currentStore = _storeConfig;

    if (currentStore == null) {
      throw StateError(
        'PlatformService must be initialized first.',
      );
    }

    initialize(
      storeConfig: currentStore,
      branchId: branchId,
    );
  }

  bool featureEnabled(String feature) {
    return _storeConfig?.features.enabled(feature) ?? false;
  }

  void reset() {
    _apiClient?.close();
    _apiClient = null;
    _tenantContext = null;
    _storeConfig = null;
  }
}
