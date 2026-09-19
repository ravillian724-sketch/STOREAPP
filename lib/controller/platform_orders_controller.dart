import 'package:ecommerce_app/core/class/statusrequest.dart';
import 'package:ecommerce_app/core/network/status_request_mapper.dart';
import 'package:ecommerce_app/data/datasource/remote/storefront_order_data.dart';
import 'package:ecommerce_app/data/model/storefront_order_model.dart';
import 'package:get/get.dart';

enum PlatformOrderFilter {
  all,
  pending,
  confirmed,
  cancelled,
}

class PlatformOrdersController extends GetxController {
  PlatformOrdersController({
    StorefrontOrderData? orderData,
  }) : orderData = orderData ?? StorefrontOrderData();

  final StorefrontOrderData orderData;

  StatusRequest statusRequest = StatusRequest.none;
  List<StorefrontOrderDetails> orders = const [];
  PlatformOrderFilter selectedFilter = PlatformOrderFilter.all;

  bool get isLoading => statusRequest == StatusRequest.loading;

  List<StorefrontOrderDetails> get visibleOrders {
    if (selectedFilter == PlatformOrderFilter.all) {
      return orders;
    }

    final requiredStatus = selectedFilter.name;

    return orders
        .where(
          (order) => order.status.toLowerCase() == requiredStatus,
        )
        .toList(growable: false);
  }

  int countFor(PlatformOrderFilter filter) {
    if (filter == PlatformOrderFilter.all) {
      return orders.length;
    }

    final status = filter.name;

    return orders
        .where(
          (order) => order.status.toLowerCase() == status,
        )
        .length;
  }

  void selectFilter(PlatformOrderFilter filter) {
    if (selectedFilter == filter) {
      return;
    }

    selectedFilter = filter;
    update();
  }

  Future<void> loadOrders() async {
    if (isLoading) {
      return;
    }

    statusRequest = StatusRequest.loading;
    update();

    try {
      orders = await orderData.getRememberedOrders();
      statusRequest = StatusRequest.success;
    } catch (error) {
      statusRequest = statusRequestForError(error);
    }

    update();
  }

  Future<void> refreshOrders() {
    return loadOrders();
  }

  @override
  void onInit() {
    loadOrders();
    super.onInit();
  }
}
