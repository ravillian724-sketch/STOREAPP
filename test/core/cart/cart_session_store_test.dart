import 'dart:math';

import 'package:ecommerce_app/core/cart/cart_session.dart';
import 'package:ecommerce_app/core/cart/cart_session_store.dart';
import 'package:ecommerce_app/core/platform/tenant_context.dart';
import 'package:flutter_test/flutter_test.dart';

class _MemoryStorage implements CartSecureStorage {
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

void main() {
  test('cart sessions are isolated by tenant and branch', () async {
    final storage = _MemoryStorage();
    final store = CartSessionStore(
      storage: storage,
      random: Random(1),
    );

    const branchA = TenantContext(
      tenantId: 'tenant-1',
      branchId: '10',
    );

    const branchB = TenantContext(
      tenantId: 'tenant-1',
      branchId: '20',
    );

    await store.saveSession(
      branchA,
      const CartSession(
        cartId: 'cart-a',
        token: 'token-a',
      ),
    );

    await store.saveSession(
      branchB,
      const CartSession(
        cartId: 'cart-b',
        token: 'token-b',
      ),
    );

    final loadedA = await store.readSession(branchA);
    final loadedB = await store.readSession(branchB);

    expect(loadedA?.cartId, 'cart-a');
    expect(loadedA?.token, 'token-a');
    expect(loadedB?.cartId, 'cart-b');
    expect(loadedB?.token, 'token-b');
  });

  test('corrupted session is removed instead of being trusted', () async {
    final storage = _MemoryStorage();
    final store = CartSessionStore(
      storage: storage,
      random: Random(2),
    );

    const context = TenantContext(
      tenantId: 'tenant-1',
      branchId: '10',
    );

    await store.saveSession(
      context,
      const CartSession(
        cartId: 'cart-a',
        token: 'token-a',
      ),
    );

    final sessionKey = storage.values.keys.single;
    storage.values[sessionKey] = '{"cart_id":""}';

    expect(
      await store.readSession(context),
      isNull,
    );
    expect(storage.values, isEmpty);
  });

  test('idempotency key is stable until operation is cleared', () async {
    final storage = _MemoryStorage();
    final store = CartSessionStore(
      storage: storage,
      random: Random(3),
    );

    const context = TenantContext(
      tenantId: 'tenant-1',
      branchId: '10',
    );

    final first = await store.idempotencyKey(
      context,
      operation: 'add-sku:5:quantity:1',
    );

    final replay = await store.idempotencyKey(
      context,
      operation: 'add-sku:5:quantity:1',
    );

    expect(replay, first);
    expect(first, hasLength(64));

    await store.clearIdempotencyKey(
      context,
      operation: 'add-sku:5:quantity:1',
    );

    final next = await store.idempotencyKey(
      context,
      operation: 'add-sku:5:quantity:1',
    );

    expect(next, isNot(first));
    expect(next, hasLength(64));
  });

  test('branch is mandatory for cart storage scope', () async {
    final store = CartSessionStore(
      storage: _MemoryStorage(),
      random: Random(4),
    );

    await expectLater(
      () => store.readSession(
        const TenantContext(
          tenantId: 'tenant-1',
        ),
      ),
      throwsStateError,
    );
  });
}
