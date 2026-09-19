class StorefrontOrderDetails {
  final String id;
  final String status;
  final String currencyCode;
  final int subtotalMinor;
  final int discountMinor;
  final int taxMinor;
  final int shippingMinor;
  final int totalMinor;
  final String? customerName;
  final DateTime? createdAt;
  final DateTime? confirmedAt;
  final List<StorefrontOrderItem> items;
  final StorefrontOrderPayment? payment;

  const StorefrontOrderDetails({
    required this.id,
    required this.status,
    required this.currencyCode,
    required this.subtotalMinor,
    required this.discountMinor,
    required this.taxMinor,
    required this.shippingMinor,
    required this.totalMinor,
    required this.customerName,
    required this.createdAt,
    required this.confirmedAt,
    required this.items,
    required this.payment,
  });

  factory StorefrontOrderDetails.fromJson(
    Map<String, dynamic> json,
  ) {
    final order = _map(
      json['order'],
      'order',
    );
    final rawItems = order['items'];

    if (rawItems is! List) {
      throw const FormatException(
        'order.items must be a JSON array.',
      );
    }

    final rawPayment = json['payment'];

    return StorefrontOrderDetails(
      id: _requiredString(
        order['id'],
        'order.id',
      ),
      status: _requiredString(
        order['status'],
        'order.status',
      ),
      currencyCode: _requiredString(
        order['currency_code'],
        'order.currency_code',
      ).toUpperCase(),
      subtotalMinor: _requiredInt(
        order['subtotal_minor'],
        'order.subtotal_minor',
      ),
      discountMinor: _requiredInt(
        order['discount_minor'],
        'order.discount_minor',
      ),
      taxMinor: _requiredInt(
        order['tax_minor'],
        'order.tax_minor',
      ),
      shippingMinor: _requiredInt(
        order['shipping_minor'],
        'order.shipping_minor',
      ),
      totalMinor: _requiredInt(
        order['total_minor'],
        'order.total_minor',
      ),
      customerName: _optionalString(
        order['customer_name'],
      ),
      createdAt: _optionalDate(
        order['created_at'],
      ),
      confirmedAt: _optionalDate(
        order['confirmed_at'],
      ),
      items: rawItems
          .map(
            (item) => StorefrontOrderItem.fromJson(
              _map(
                item,
                'order.items[]',
              ),
            ),
          )
          .toList(growable: false),
      payment: rawPayment == null
          ? null
          : StorefrontOrderPayment.fromJson(
              _map(
                rawPayment,
                'payment',
              ),
            ),
    );
  }
}

class StorefrontOrderItem {
  final String id;
  final String skuCode;
  final String productNameAr;
  final String productNameEn;
  final int quantity;
  final int unitNetMinor;
  final int taxMinor;
  final int lineTotalMinor;

  const StorefrontOrderItem({
    required this.id,
    required this.skuCode,
    required this.productNameAr,
    required this.productNameEn,
    required this.quantity,
    required this.unitNetMinor,
    required this.taxMinor,
    required this.lineTotalMinor,
  });

  factory StorefrontOrderItem.fromJson(
    Map<String, dynamic> json,
  ) {
    return StorefrontOrderItem(
      id: _requiredString(
        json['id'],
        'order.items[].id',
      ),
      skuCode: _requiredString(
        json['sku_code'],
        'order.items[].sku_code',
      ),
      productNameAr: _requiredString(
        json['product_name_ar'],
        'order.items[].product_name_ar',
      ),
      productNameEn: _requiredString(
        json['product_name_en'],
        'order.items[].product_name_en',
      ),
      quantity: _requiredInt(
        json['quantity'],
        'order.items[].quantity',
      ),
      unitNetMinor: _requiredInt(
        json['unit_net_minor'],
        'order.items[].unit_net_minor',
      ),
      taxMinor: _requiredInt(
        json['tax_minor'],
        'order.items[].tax_minor',
      ),
      lineTotalMinor: _requiredInt(
        json['line_total_minor'],
        'order.items[].line_total_minor',
      ),
    );
  }
}

class StorefrontOrderPayment {
  final String id;
  final String status;
  final String currencyCode;
  final int amountMinor;

  const StorefrontOrderPayment({
    required this.id,
    required this.status,
    required this.currencyCode,
    required this.amountMinor,
  });

  factory StorefrontOrderPayment.fromJson(
    Map<String, dynamic> json,
  ) {
    return StorefrontOrderPayment(
      id: _requiredString(
        json['id'],
        'payment.id',
      ),
      status: _requiredString(
        json['status'],
        'payment.status',
      ),
      currencyCode: _requiredString(
        json['currency_code'],
        'payment.currency_code',
      ).toUpperCase(),
      amountMinor: _requiredInt(
        json['amount_minor'],
        'payment.amount_minor',
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

String? _optionalString(dynamic value) {
  final normalized = value?.toString().trim() ?? '';

  return normalized.isEmpty ? null : normalized;
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

DateTime? _optionalDate(dynamic value) {
  final normalized = value?.toString().trim() ?? '';

  if (normalized.isEmpty) {
    return null;
  }

  final parsed = DateTime.tryParse(
    normalized,
  );

  if (parsed == null) {
    throw const FormatException(
      'Order date must be ISO-8601.',
    );
  }

  return parsed;
}
