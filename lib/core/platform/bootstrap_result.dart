import 'store_config.dart';

class BootstrapResult {
  final StoreConfig storeConfig;
  final String? defaultBranchId;

  const BootstrapResult({
    required this.storeConfig,
    this.defaultBranchId,
  });

  factory BootstrapResult.fromJson(
    Map<String, dynamic> json,
  ) {
    final rawStore = json['store'] as Map<String, dynamic>?;

    if (rawStore == null) {
      throw const FormatException(
        'Bootstrap response does not contain store configuration.',
      );
    }

    final storeConfig = StoreConfig.fromJson(rawStore);

    if (storeConfig.tenantId.trim().isEmpty) {
      throw const FormatException(
        'Bootstrap response does not contain a valid tenant.',
      );
    }

    return BootstrapResult(
      storeConfig: storeConfig,
      defaultBranchId: json['default_branch_id']?.toString(),
    );
  }
}
