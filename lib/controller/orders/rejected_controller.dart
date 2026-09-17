import 'package:get/get.dart';
import '../../core/class/statusrequest.dart';
import '../../core/constant/routes.dart';
import '../../core/functions/handlingdatacontroller.dart';
import '../../core/services/services.dart';
import '../../data/datasource/remote/orders/rejected_data.dart';
import '../../data/model/ordersmodel.dart';
import 'package:ecommerce_app/core/functions/session_guard.dart';

import 'package:ecommerce_app/core/logging/app_logger.dart';

class OrdersRejectedController extends GetxController {
  OrdersRejectedData ordersRejectedData = OrdersRejectedData(Get.find());
  List<OrdersModel> data = [];
  StatusRequest statusRequest = StatusRequest.none;
  MyServices myServices = Get.find();

  String printOrderType(String val) {
    if (val == "0") {
      return "137".tr; //Delivery
    } else {
      return "138".tr; //Local
    }
  }

  String printPaymentMethod(String val) {
    if (val == "0") {
      return "139".tr; //Cash On Delivery
    } else {
      return "140"; //Syriatel Cash
    }
  }

  String printOrdersStatus(String val) {
    if (val == "-1") {
      return "Rejected".tr;
    } else {
      return "Unknown".tr;
    }
  }

  getOrders() async {
    final userId = await requireUserId(myServices);
    if (userId == null) {
      return;
    }
    data.clear();
    statusRequest = StatusRequest.loading;
    update();
    var response = await ordersRejectedData.getData(userId);
    appDebugLog(
        "========================================Controller  $response");
    statusRequest = handlingData(response);
    if (StatusRequest.success == statusRequest) {
      if (response['status'] == "success") {
        List listdata = response['data'];
        data.addAll(listdata.map((e) => OrdersModel.fromJson(e)));
      } else {
        statusRequest = StatusRequest.none;
      }
    }
    update();
  }

  refreshOrder() {
    getOrders();
  }

  goToPageTracking(OrdersModel ordersmodel) {
    Get.toNamed(AppRoute.tracking, arguments: {
      "ordersmodel": ordersmodel,
    });
  }

  @override
  void onInit() {
    getOrders();
    super.onInit();
  }
}
