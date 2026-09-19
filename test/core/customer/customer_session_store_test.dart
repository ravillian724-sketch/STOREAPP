import 'package:ecommerce_app/core/customer/customer_session_store.dart';
import 'package:ecommerce_app/core/platform/tenant_context.dart';
import 'package:ecommerce_app/data/model/storefront_customer_model.dart';
import 'package:flutter_test/flutter_test.dart';

class _MemoryStorage implements CustomerSecureStorage {
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

const _tenantA = TenantContext(
  tenantId: 'tenant-a',
  branchId: '10',
);

const _tenantB = TenantContext(
  tenantId: 'tenant-b',
  branchId: '10',
);

StorefrontCustomerSession _session({
  DateTime? expiresAt,
}) {
  return StorefrontCustomerSession(
    accessToken: 'secret-customer-token',
    expiresAt: expiresAt,
    customer: const StorefrontCustomer(
      id: 'customer-1',
      name: 'Buyer',
      email: 'buyer@example.com',
      phone: '+966500000000',
      emailVerified: false,
    ),
  );
}

void main() {
  test('customer session is scoped by tenant and round trips', () async {
    final storage = _MemoryStorage();
    final store = CustomerSessionStore(
      storage: storage,
    );

    await store.saveSession(
      _tenantA,
      _session(
        expiresAt: DateTime.now().toUtc().add(const Duration(days: 1)),
      ),
    );

    final restored = await store.readSession(
      _tenantA,
    );

    expect(
      restored?.accessToken,
      'secret-customer-token',
    );
    expect(
      restored?.customer.email,
      'buyer@example.com',
    );
    expect(
      await store.readSession(_tenantB),
      isNull,
    );
  });

  test('expired customer session is deleted', () async {
    final storage = _MemoryStorage();
    final store = CustomerSessionStore(
      storage: storage,
    );

    await store.saveSession(
      _tenantA,
      _session(
        expiresAt: DateTime.now().toUtc().subtract(const Duration(seconds: 1)),
      ),
    );

    expect(
      await store.readSession(_tenantA),
      isNull,
    );
    expect(storage.values, isEmpty);
  });

  test('corrupt customer session is deleted', () async {
    final storage = _MemoryStorage();
    final store = CustomerSessionStore(
      storage: storage,
    );

    storage.values['storeapp.customer.v1.dGVuYW50LWE.session'] = '{not-json';

    expect(
      await store.readSession(_tenantA),
      isNull,
    );
    expect(storage.values, isEmpty);
  });

  test('clear session removes only selected tenant', () async {
    final storage = _MemoryStorage();
    final store = CustomerSessionStore(
      storage: storage,
    );

    await store.saveSession(
      _tenantA,
      _session(),
    );
    await store.saveSession(
      _tenantB,
      _session(),
    );

    await store.clearSession(_tenantA);

    expect(
      await store.readSession(_tenantA),
      isNull,
    );
    expect(
      await store.readSession(_tenantB),
      isNotNull,
    );
  });
}
