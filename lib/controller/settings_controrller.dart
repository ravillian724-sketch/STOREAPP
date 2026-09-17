import 'package:ecommerce_app/core/constant/routes.dart';
import 'package:ecommerce_app/core/services/services.dart';
import 'package:ecommerce_app/data/datasource/remote/users_data.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:get/get.dart';

import '../core/class/statusrequest.dart';
import '../core/functions/handlingdatacontroller.dart';
import '../data/model/usersmodel.dart';

import 'package:ecommerce_app/core/logging/app_logger.dart';

class SettingsController extends GetxController {
  MyServices myServices = Get.find();
  List<UsersModel> data = [];
  StatusRequest statusRequest = StatusRequest.none;
  UsersData usersData = UsersData(Get.find());

  Future<void> logout() async {
    final String? userid = myServices.userId;

    try {
      await FirebaseMessaging.instance.unsubscribeFromTopic("users");

      if (userid != null) {
        await FirebaseMessaging.instance.unsubscribeFromTopic("users$userid");
      }
    } catch (_) {
      // Logout must continue even if FCM unsubscribe fails.
    }

    await myServices.clearUserSession();
    Get.offAllNamed(AppRoute.login);
  }

  getData() async {
    statusRequest = StatusRequest.loading;

    var response = await usersData.getData();
    appDebugLog(
        "========================================Controller  $response");
    statusRequest = handlingData(response);
    if (StatusRequest.success == statusRequest) {
      if (response['status'] == "success") {
        List datalist = response['data'];
        data.addAll(datalist.map((e) => UsersModel.fromJson(e)).toList());
      } else {
        statusRequest = StatusRequest.failure;
      }
    }
    update();
  }

  @override
  void onInit() {
    if (myServices.hasValidSession) {
      getData();
    }
    super.onInit();
  }
}
