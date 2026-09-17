import 'package:ecommerce_app/core/class/statusrequest.dart';
import 'package:ecommerce_app/core/constant/routes.dart';
import 'package:ecommerce_app/core/services/notification_service.dart';
import 'package:ecommerce_app/core/services/services.dart';
import 'package:ecommerce_app/data/datasource/remote/address_data.dart';
import 'package:flutter/cupertino.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../core/functions/handlingdatacontroller.dart';
import 'package:ecommerce_app/core/functions/session_guard.dart';

import 'package:ecommerce_app/core/logging/app_logger.dart';

class AddAddressDetailsController extends GetxController {
  final formKey = GlobalKey<FormState>();
  String? lat;
  String? long;
  TextEditingController? name;
  TextEditingController? city;
  TextEditingController? street;
  TextEditingController? note;

  StatusRequest statusRequest = StatusRequest.none;
  AddressData addressData = AddressData(Get.find());

  NotificationService notificationService = Get.find();
  MyServices myServices = Get.find();
  intialData() {
    name = TextEditingController();
    city = TextEditingController();
    street = TextEditingController();
    note = TextEditingController();

    final arguments = Get.arguments;
    if (arguments is Map) {
      lat = arguments["lat"]?.toString();
      long = arguments["long"]?.toString();
    }
  }

  addAddress() async {
    if (!(formKey.currentState?.validate() ?? false)) {
      return;
    }

    if (lat == null || lat!.isEmpty || long == null || long!.isEmpty) {
      notificationService.showErrorNotification(
        title: "Address".tr,
        message: "Location data is missing. Please select the address again.",
      );
      return;
    }

    final userId = await requireUserId(myServices);
    if (userId == null) {
      return;
    }

    statusRequest = StatusRequest.loading;
    update();
    var response = await addressData.addData(
        userId, name!.text, city!.text, street!.text, note!.text, lat!, long!);
    appDebugLog(
        "========================================Controller  $response");
    statusRequest = handlingData(response);
    if (StatusRequest.success == statusRequest) {
      if (response['status'] == "success") {
        Get.offAllNamed(AppRoute.homepage);
        notificationService.showErrorNotification(
            title: "Good".tr, message: "Now, You Can Orders To This Address");
      } else {
        statusRequest = StatusRequest.failure;
      }
    }
    update();
  }

  @override
  void onInit() {
    intialData();
    super.onInit();
  }
}
