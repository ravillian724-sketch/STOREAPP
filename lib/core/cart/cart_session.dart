class CartSession {
  final String cartId;
  final String token;

  const CartSession({
    required this.cartId,
    required this.token,
  });

  Map<String, dynamic> toJson() => {
        'cart_id': cartId,
        'token': token,
      };

  factory CartSession.fromJson(Map<String, dynamic> json) {
    final cartId = json['cart_id']?.toString().trim() ?? '';
    final token = json['token']?.toString().trim() ?? '';

    if (cartId.isEmpty || token.isEmpty) {
      throw const FormatException('Cart session is incomplete.');
    }

    return CartSession(
      cartId: cartId,
      token: token,
    );
  }
}
