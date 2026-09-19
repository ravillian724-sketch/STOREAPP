import 'package:ecommerce_app/controller/platform_orders_controller.dart';
import 'package:ecommerce_app/core/class/statusrequest.dart';
import 'package:ecommerce_app/data/datasource/remote/storefront_order_data.dart';
import 'package:ecommerce_app/data/model/storefront_order_model.dart';
import 'package:flutter_test/flutter_test.dart';

class _FakeOrderData extends StorefrontOrderData {
  _FakeOrderData(this.values);

  final List<StorefrontOrderDetails> values;

  @override
  Future<List<StorefrontOrderDetails>> getRememberedOrders() async {
    return values;
  }
}

StorefrontOrderDetails _order(
  String id,
  String status,
) {
  return StorefrontOrderDetails(
    id: id,
    status: status,
    currencyCode: 'SAR',
    subtotalMinor: 1000,
    discountMinor: 0,
    taxMinor: 150,
    shippingMinor: 0,
    totalMinor: 1150,
    customerName: 'Buyer',
    createdAt: DateTime.utc(2026, 9, 19),
    confirmedAt: status == 'confirmed' ? DateTime.utc(2026, 9, 19, 1) : null,
    items: const [],
    payment: null,
  );
}

void main() {
  test('orders controller filters account history without mutating source',
      () async {
    final source = [
      _order('order-pending', 'pending'),
      _order('order-confirmed', 'confirmed'),
      _order('order-cancelled', 'cancelled'),
      _order('order-confirmed-2', 'confirmed'),
    ];

    final controller = PlatformOrdersController(
      orderData: _FakeOrderData(source),
    );

    await controller.loadOrders();

    expect(controller.statusRequest, StatusRequest.success);
    expect(controller.orders, hasLength(4));
    expect(controller.visibleOrders, hasLength(4));
    expect(
      controller.countFor(PlatformOrderFilter.confirmed),
      2,
    );

    controller.selectFilter(
      PlatformOrderFilter.confirmed,
    );

    expect(
      controller.visibleOrders.map((order) => order.id).toList(),
      ['order-confirmed', 'order-confirmed-2'],
    );
    expect(controller.orders, hasLength(4));

    controller.selectFilter(
      PlatformOrderFilter.cancelled,
    );

    expect(
      controller.visibleOrders.single.id,
      'order-cancelled',
    );
  });
}
