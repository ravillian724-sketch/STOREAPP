class StorefrontCartSnapshot {
  final String cartId;
  final String status;
  final DateTime? expiresAt;
  final DateTime? inventoryReservedUntil;
  final String branchId;
  final String currencyCode;
  final List<StorefrontCartItem> items;
  final StorefrontCartTotals totals;

  const StorefrontCartSnapshot({
    required this.cartId,
    required this.status,
    required this.expiresAt,
    required this.inventoryReservedUntil,
    required this.branchId,
    required this.currencyCode,
    required this.items,
    required this.totals,
  });

  factory StorefrontCartSnapshot.fromJson(
    Map<String, dynamic> json,
  ) {
    final cart = _map(json['cart'], 'cart');
    final branch = _map(json['branch'], 'branch');
    final rawItems = json['items'];

    if (rawItems is! List) {
      throw const FormatException(
        'Cart items must be a list.',
      );
    }

    return StorefrontCartSnapshot(
      cartId: _requiredString(
        cart['id'],
        'cart.id',
      ),
      status: _requiredString(
        cart['status'],
        'cart.status',
      ),
      expiresAt: _dateTime(
        cart['expires_at'],
        'cart.expires_at',
      ),
      inventoryReservedUntil: _dateTime(
        cart['inventory_reserved_until'],
        'cart.inventory_reserved_until',
      ),
      branchId: _requiredString(
        branch['id'],
        'branch.id',
      ),
      currencyCode: _requiredString(
        json['currency_code'],
        'currency_code',
      ).toUpperCase(),
      items: rawItems
          .map(
            (item) => StorefrontCartItem.fromJson(
              _map(item, 'items[]'),
            ),
          )
          .toList(growable: false),
      totals: StorefrontCartTotals.fromJson(
        _map(
          json['totals'],
          'totals',
        ),
      ),
    );
  }

  int get totalQuantity => items.fold<int>(
        0,
        (sum, item) => sum + item.quantity,
      );
}

class StorefrontCartItem {
  final String cartItemId;
  final String productId;
  final String skuId;
  final String? code;
  final String? barcode;
  final String? nameAr;
  final String? nameEn;
  final String? descriptionAr;
  final String? descriptionEn;
  final String? imageUrl;
  final int quantity;
  final int maxQuantity;
  final StorefrontCartItemPricing pricing;

  const StorefrontCartItem({
    required this.cartItemId,
    required this.productId,
    required this.skuId,
    required this.code,
    required this.barcode,
    required this.nameAr,
    required this.nameEn,
    required this.descriptionAr,
    required this.descriptionEn,
    required this.imageUrl,
    required this.quantity,
    required this.maxQuantity,
    required this.pricing,
  });

  factory StorefrontCartItem.fromJson(
    Map<String, dynamic> json,
  ) {
    return StorefrontCartItem(
      cartItemId: _requiredString(
        json['cart_item_id'],
        'cart_item_id',
      ),
      productId: _requiredString(
        json['product_id'],
        'product_id',
      ),
      skuId: _requiredString(
        json['sku_id'],
        'sku_id',
      ),
      code: _nullableString(json['code']),
      barcode: _nullableString(json['barcode']),
      nameAr: _nullableString(json['name_ar']),
      nameEn: _nullableString(json['name_en']),
      descriptionAr: _nullableString(
        json['description_ar'],
      ),
      descriptionEn: _nullableString(
        json['description_en'],
      ),
      imageUrl: _nullableString(json['image_url']),
      quantity: _requiredInt(
        json['quantity'],
        'quantity',
      ),
      maxQuantity: _requiredInt(
        json['max_quantity'],
        'max_quantity',
      ),
      pricing: StorefrontCartItemPricing.fromJson(
        _map(
          json['pricing'],
          'pricing',
        ),
      ),
    );
  }
}

class StorefrontCartItemPricing {
  final String currencyCode;
  final int displayUnitAmountMinor;
  final int lineSubtotalMinor;
  final int discountMinor;
  final int taxMinor;
  final int lineTotalMinor;

  const StorefrontCartItemPricing({
    required this.currencyCode,
    required this.displayUnitAmountMinor,
    required this.lineSubtotalMinor,
    required this.discountMinor,
    required this.taxMinor,
    required this.lineTotalMinor,
  });

  factory StorefrontCartItemPricing.fromJson(
    Map<String, dynamic> json,
  ) {
    return StorefrontCartItemPricing(
      currencyCode: _requiredString(
        json['currency_code'],
        'pricing.currency_code',
      ).toUpperCase(),
      displayUnitAmountMinor: _requiredInt(
        json['display_unit_amount_minor'],
        'pricing.display_unit_amount_minor',
      ),
      lineSubtotalMinor: _requiredInt(
        json['line_subtotal_minor'],
        'pricing.line_subtotal_minor',
      ),
      discountMinor: _requiredInt(
        json['discount_minor'],
        'pricing.discount_minor',
      ),
      taxMinor: _requiredInt(
        json['tax_minor'],
        'pricing.tax_minor',
      ),
      lineTotalMinor: _requiredInt(
        json['line_total_minor'],
        'pricing.line_total_minor',
      ),
    );
  }
}

class StorefrontCartTotals {
  final int subtotalMinor;
  final int discountMinor;
  final int taxMinor;
  final int shippingMinor;
  final int totalMinor;
  final DateTime? quotedAt;
  final DateTime? quoteExpiresAt;

  const StorefrontCartTotals({
    required this.subtotalMinor,
    required this.discountMinor,
    required this.taxMinor,
    required this.shippingMinor,
    required this.totalMinor,
    required this.quotedAt,
    required this.quoteExpiresAt,
  });

  factory StorefrontCartTotals.fromJson(
    Map<String, dynamic> json,
  ) {
    return StorefrontCartTotals(
      subtotalMinor: _requiredInt(
        json['subtotal_minor'],
        'totals.subtotal_minor',
      ),
      discountMinor: _requiredInt(
        json['discount_minor'],
        'totals.discount_minor',
      ),
      taxMinor: _requiredInt(
        json['tax_minor'],
        'totals.tax_minor',
      ),
      shippingMinor: _requiredInt(
        json['shipping_minor'],
        'totals.shipping_minor',
      ),
      totalMinor: _requiredInt(
        json['total_minor'],
        'totals.total_minor',
      ),
      quotedAt: _dateTime(
        json['quoted_at'],
        'totals.quoted_at',
      ),
      quoteExpiresAt: _dateTime(
        json['quote_expires_at'],
        'totals.quote_expires_at',
      ),
    );
  }
}

Map<String, dynamic> _map(
  dynamic value,
  String field,
) {
  if (value is! Map) {
    throw FormatException(
      '$field must be a JSON object.',
    );
  }

  return Map<String, dynamic>.from(value);
}

String _requiredString(
  dynamic value,
  String field,
) {
  final normalized = value?.toString().trim() ?? '';

  if (normalized.isEmpty) {
    throw FormatException(
      '$field is required.',
    );
  }

  return normalized;
}

String? _nullableString(dynamic value) {
  final normalized = value?.toString().trim();

  if (normalized == null || normalized.isEmpty) {
    return null;
  }

  return normalized;
}

int _requiredInt(
  dynamic value,
  String field,
) {
  final parsed = int.tryParse(
    value?.toString() ?? '',
  );

  if (parsed == null) {
    throw FormatException(
      '$field must be an integer.',
    );
  }

  return parsed;
}

DateTime? _dateTime(
  dynamic value,
  String field,
) {
  final normalized = value?.toString().trim();

  if (normalized == null || normalized.isEmpty) {
    return null;
  }

  final parsed = DateTime.tryParse(normalized);

  if (parsed == null) {
    throw FormatException(
      '$field must be an ISO-8601 date.',
    );
  }

  return parsed;
}
