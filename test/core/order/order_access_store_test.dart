import 'dart:math';

import 'package:ecommerce_app/core/order/order_access_store.dart';
import 'package:ecommerce_app/core/platform/tenant_context.dart';
import 'package:flutter_test/flutter_test.dart';

class _MemoryStorage implements OrderSecureStorage {
  final Map<String, String> values = {};

  @override
  Future<void> delete(String key) async {
    values.remove(key);
  }

  @override
  Future<String?> read(String key) async {
    return values[key];
  }

  @override
  Future<void> write(
    String key,
    String value,
  ) async {
    values[key] = value;
  }
}

const _branchA = TenantContext(
  tenantId: 'tenant-1',
  brandId: 'brand-1',
  branchId: '10',
);
const _branchB = TenantContext(
  tenantId: 'tenant-1',
  brandId: 'brand-1',
  branchId: '20',
);

const _otherTenant = TenantContext(
  tenantId: 'tenant-2',
  brandId: 'brand-1',
  branchId: '10',
);

void main() {
  test('checkout token survives retry until payment completion', () async {
    final storage = _MemoryStorage();
    final store = OrderAccessStore(
      storage: storage,
      random: Random(7),
    );

    final first = await store.checkoutToken(
      _branchA,
      cartId: 'cart-1',
    );
    final retry = await store.checkoutToken(
      _branchA,
      cartId: 'cart-1',
    );

    expect(first, hasLength(64));
    expect(
      RegExp(r'^[0-9a-f]{64}$').hasMatch(first),
      isTrue,
    );
    expect(retry, first);
    await store.saveOrder(
      _branchA,
      cartId: 'cart-1',
      orderId: 'order-1',
      token: first,
    );

    expect(
      await store.checkoutToken(
        _branchA,
        cartId: 'cart-1',
      ),
      first,
    );

    await store.clearCheckoutToken(
      _branchA,
      cartId: 'cart-1',
    );

    final next = await store.checkoutToken(
      _branchA,
      cartId: 'cart-1',
    );

    expect(next, isNot(first));
  });

  test('order access is branch independent but tenant scoped', () async {
    final storage = _MemoryStorage();
    final store = OrderAccessStore(
      storage: storage,
      random: Random(8),
    );

    final token = await store.checkoutToken(
      _branchA,
      cartId: 'cart-2',
    );

    await store.saveOrder(
      _branchA,
      cartId: 'cart-2',
      orderId: 'order-2',
      token: token,
    );

    expect(
      await store.readOrderToken(
        _branchB,
        'order-2',
      ),
      token,
    );
    expect(
      await store.listOrderIds(_branchB),
      ['order-2'],
    );

    expect(
      await store.readOrderToken(
        _otherTenant,
        'order-2',
      ),
      isNull,
    );
    expect(
      await store.listOrderIds(_otherTenant),
      isEmpty,
    );
  });

  test('removing an order deletes its credential and prunes the index',
      () async {
    final storage = _MemoryStorage();
    final store = OrderAccessStore(
      storage: storage,
      random: Random(11),
    );

    final firstToken = await store.checkoutToken(
      _branchA,
      cartId: 'cart-remove-1',
    );
    await store.saveOrder(
      _branchA,
      cartId: 'cart-remove-1',
      orderId: 'order-remove-1',
      token: firstToken,
    );

    final secondToken = await store.checkoutToken(
      _branchA,
      cartId: 'cart-remove-2',
    );
    await store.saveOrder(
      _branchA,
      cartId: 'cart-remove-2',
      orderId: 'order-remove-2',
      token: secondToken,
    );

    await store.removeOrder(
      _branchA,
      'order-remove-1',
    );

    expect(
      await store.readOrderToken(
        _branchA,
        'order-remove-1',
      ),
      isNull,
    );
    expect(
      await store.listOrderIds(_branchA),
      ['order-remove-2'],
    );

    await store.removeOrder(
      _branchA,
      'order-remove-2',
    );

    expect(
      await store.listOrderIds(_branchA),
      isEmpty,
    );
  });
}
