import 'package:ecommerce_app/core/services/services.dart';
import 'package:get/get.dart';

import '../core/class/statusrequest.dart';
import '../core/functions/handlingdatacontroller.dart';
import '../data/datasource/remote/notification_data.dart';
import 'package:ecommerce_app/core/functions/session_guard.dart';

import 'package:ecommerce_app/core/logging/app_logger.dart';

class NotificationContrller extends GetxController {
  MyServices myServices = Get.find();

  NotificationData notificationData = NotificationData(Get.find());

  List data = [];
  StatusRequest statusRequest = StatusRequest.none;
  getData() async {
    final userId = await requireUserId(myServices);
    if (userId == null) {
      return;
    }
    statusRequest = StatusRequest.loading;
    update();
    var response = await notificationData.getData(userId);
    appDebugLog(
        "========================================Controller  $response");
    statusRequest = handlingData(response);
    if (StatusRequest.success == statusRequest) {
      if (response['status'] == "success") {
        data.addAll(response['data']);
      } else {
        statusRequest = StatusRequest.failure;
      }
    }
    update();
  }

  @override
  void onInit() {
    getData();
    super.onInit();
  }
}
