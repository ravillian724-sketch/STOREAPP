class StorefrontCheckoutQuote {
  final String cartId;
  final String currencyCode;
  final DateTime inventoryReservedUntil;
  final DateTime quotedAt;
  final DateTime expiresAt;
  final int subtotalMinor;
  final int discountMinor;
  final int taxMinor;
  final int shippingMinor;
  final int totalMinor;

  const StorefrontCheckoutQuote({
    required this.cartId,
    required this.currencyCode,
    required this.inventoryReservedUntil,
    required this.quotedAt,
    required this.expiresAt,
    required this.subtotalMinor,
    required this.discountMinor,
    required this.taxMinor,
    required this.shippingMinor,
    required this.totalMinor,
  });

  factory StorefrontCheckoutQuote.fromJson(Map<String, dynamic> json) {
    final quote = _map(json['quote'], 'quote');

    return StorefrontCheckoutQuote(
      cartId: _requiredString(json['cart_id'], 'cart_id'),
      currencyCode: _requiredString(
        json['currency_code'],
        'currency_code',
      ).toUpperCase(),
      inventoryReservedUntil: _requiredDate(
        json['inventory_reserved_until'],
        'inventory_reserved_until',
      ),
      quotedAt: _requiredDate(
        quote['quoted_at'],
        'quote.quoted_at',
      ),
      expiresAt: _requiredDate(
        quote['expires_at'],
        'quote.expires_at',
      ),
      subtotalMinor: _requiredInt(
        quote['subtotal_minor'],
        'quote.subtotal_minor',
      ),
      discountMinor: _requiredInt(
        quote['discount_minor'],
        'quote.discount_minor',
      ),
      taxMinor: _requiredInt(
        quote['tax_minor'],
        'quote.tax_minor',
      ),
      shippingMinor: _requiredInt(
        quote['shipping_minor'],
        'quote.shipping_minor',
      ),
      totalMinor: _requiredInt(
        quote['total_minor'],
        'quote.total_minor',
      ),
    );
  }
}

class StorefrontCheckoutOrder {
  final String id;
  final String status;
  final String currencyCode;
  final int totalMinor;

  const StorefrontCheckoutOrder({
    required this.id,
    required this.status,
    required this.currencyCode,
    required this.totalMinor,
  });

  factory StorefrontCheckoutOrder.fromJson(Map<String, dynamic> json) {
    final order = _map(json['order'], 'order');

    return StorefrontCheckoutOrder(
      id: _requiredString(order['id'], 'order.id'),
      status: _requiredString(order['status'], 'order.status'),
      currencyCode: _requiredString(
        order['currency_code'],
        'order.currency_code',
      ).toUpperCase(),
      totalMinor: _requiredInt(
        order['total_minor'],
        'order.total_minor',
      ),
    );
  }
}

class StorefrontPaymentAttemptResult {
  final String paymentId;
  final String paymentStatus;
  final String currencyCode;
  final int amountMinor;
  final String attemptId;
  final String attemptStatus;
  final String providerCode;
  final String methodCode;

  const StorefrontPaymentAttemptResult({
    required this.paymentId,
    required this.paymentStatus,
    required this.currencyCode,
    required this.amountMinor,
    required this.attemptId,
    required this.attemptStatus,
    required this.providerCode,
    required this.methodCode,
  });

  factory StorefrontPaymentAttemptResult.fromJson(
    Map<String, dynamic> json,
  ) {
    final payment = _map(json['payment'], 'payment');
    final attempt = _map(json['attempt'], 'attempt');

    return StorefrontPaymentAttemptResult(
      paymentId: _requiredString(
        payment['id'],
        'payment.id',
      ),
      paymentStatus: _requiredString(
        payment['status'],
        'payment.status',
      ),
      currencyCode: _requiredString(
        payment['currency_code'],
        'payment.currency_code',
      ).toUpperCase(),
      amountMinor: _requiredInt(
        payment['amount_minor'],
        'payment.amount_minor',
      ),
      attemptId: _requiredString(
        attempt['id'],
        'attempt.id',
      ),
      attemptStatus: _requiredString(
        attempt['status'],
        'attempt.status',
      ),
      providerCode: _requiredString(
        attempt['provider_code'],
        'attempt.provider_code',
      ),
      methodCode: _requiredString(
        attempt['method_code'],
        'attempt.method_code',
      ),
    );
  }
}

class StorefrontSandboxSettlement {
  final String scenario;
  final StorefrontCheckoutOrder order;
  final StorefrontPaymentAttemptResult paymentAttempt;

  const StorefrontSandboxSettlement({
    required this.scenario,
    required this.order,
    required this.paymentAttempt,
  });

  bool get succeeded =>
      order.status == 'confirmed' &&
      paymentAttempt.paymentStatus == 'paid' &&
      paymentAttempt.attemptStatus == 'succeeded';

  bool get declined => paymentAttempt.attemptStatus == 'failed';

  factory StorefrontSandboxSettlement.fromJson(
    Map<String, dynamic> json,
  ) {
    return StorefrontSandboxSettlement(
      scenario: _requiredString(
        json['scenario'],
        'scenario',
      ),
      order: StorefrontCheckoutOrder.fromJson(json),
      paymentAttempt: StorefrontPaymentAttemptResult.fromJson(json),
    );
  }
}

Map<String, dynamic> _map(dynamic value, String field) {
  if (value is! Map) {
    throw FormatException('$field must be a JSON object.');
  }

  return Map<String, dynamic>.from(value);
}

String _requiredString(dynamic value, String field) {
  final normalized = value?.toString().trim() ?? '';

  if (normalized.isEmpty) {
    throw FormatException('$field is required.');
  }

  return normalized;
}

int _requiredInt(dynamic value, String field) {
  final parsed = int.tryParse(value?.toString() ?? '');

  if (parsed == null) {
    throw FormatException('$field must be an integer.');
  }

  return parsed;
}

DateTime _requiredDate(dynamic value, String field) {
  final parsed = DateTime.tryParse(
    value?.toString().trim() ?? '',
  );

  if (parsed == null) {
    throw FormatException('$field must be an ISO-8601 date.');
  }

  return parsed;
}
