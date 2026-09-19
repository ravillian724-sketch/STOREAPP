class StorefrontCustomer {
  const StorefrontCustomer({
    required this.id,
    required this.name,
    required this.email,
    required this.emailVerified,
    this.phone,
    this.createdAt,
  });

  final String id;
  final String name;
  final String email;
  final String? phone;
  final bool emailVerified;
  final DateTime? createdAt;

  factory StorefrontCustomer.fromJson(
    Map<String, dynamic> json,
  ) {
    final id = json['id']?.toString().trim() ?? '';
    final name = json['name']?.toString().trim() ?? '';
    final email = json['email']?.toString().trim() ?? '';

    if (id.isEmpty || name.isEmpty || email.isEmpty) {
      throw const FormatException(
        'Customer response is missing required identity fields.',
      );
    }

    final rawPhone = json['phone']?.toString().trim();
    final rawCreatedAt = json['created_at']?.toString().trim();

    return StorefrontCustomer(
      id: id,
      name: name,
      email: email,
      phone: rawPhone == null || rawPhone.isEmpty ? null : rawPhone,
      emailVerified: json['email_verified'] == true,
      createdAt: rawCreatedAt == null || rawCreatedAt.isEmpty
          ? null
          : DateTime.tryParse(rawCreatedAt),
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'name': name,
      'email': email,
      'phone': phone,
      'email_verified': emailVerified,
      'created_at': createdAt?.toIso8601String(),
    };
  }
}

class StorefrontCustomerSession {
  const StorefrontCustomerSession({
    required this.accessToken,
    required this.customer,
    this.expiresAt,
  });

  final String accessToken;
  final StorefrontCustomer customer;
  final DateTime? expiresAt;

  bool get isExpired {
    final expiration = expiresAt;

    if (expiration == null) {
      return false;
    }

    return !expiration.isAfter(
      DateTime.now().toUtc(),
    );
  }

  factory StorefrontCustomerSession.fromJson(
    Map<String, dynamic> json,
  ) {
    final token = json['access_token']?.toString().trim() ?? '';
    final customerRaw = json['customer'];
    final expiresRaw = json['expires_at']?.toString().trim();

    if (token.isEmpty || customerRaw is! Map) {
      throw const FormatException(
        'Customer session is missing token or customer identity.',
      );
    }

    return StorefrontCustomerSession(
      accessToken: token,
      customer: StorefrontCustomer.fromJson(
        Map<String, dynamic>.from(customerRaw),
      ),
      expiresAt: expiresRaw == null || expiresRaw.isEmpty
          ? null
          : DateTime.tryParse(expiresRaw)?.toUtc(),
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'access_token': accessToken,
      'expires_at': expiresAt?.toIso8601String(),
      'customer': customer.toJson(),
    };
  }
}
