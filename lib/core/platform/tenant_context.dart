class TenantContext {
  final String tenantId;
  final String? brandId;
  final String? branchId;

  const TenantContext({
    required this.tenantId,
    this.brandId,
    this.branchId,
  });

  bool get hasBrand =>
      brandId != null && brandId!.trim().isNotEmpty;

  bool get hasBranch =>
      branchId != null && branchId!.trim().isNotEmpty;

  TenantContext copyWith({
    String? tenantId,
    String? brandId,
    String? branchId,
  }) {
    return TenantContext(
      tenantId: tenantId ?? this.tenantId,
      brandId: brandId ?? this.brandId,
      branchId: branchId ?? this.branchId,
    );
  }
}
