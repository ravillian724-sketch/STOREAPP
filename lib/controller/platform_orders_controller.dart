import 'package:ecommerce_app/core/class/statusrequest.dart';
import 'package:ecommerce_app/core/network/status_request_mapper.dart';
import 'package:ecommerce_app/data/datasource/remote/storefront_order_data.dart';
import 'package:ecommerce_app/data/model/storefront_order_model.dart';
import 'package:get/get.dart';

class PlatformOrdersController extends GetxController {
  PlatformOrdersController({
    StorefrontOrderData? orderData,
  }) : orderData = orderData ?? StorefrontOrderData();

  final StorefrontOrderData orderData;

  StatusRequest statusRequest = StatusRequest.none;
  List<StorefrontOrderDetails> orders = const [];

  bool get isLoading => statusRequest == StatusRequest.loading;

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
