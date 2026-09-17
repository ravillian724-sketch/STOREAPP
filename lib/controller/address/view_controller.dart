import 'package:ecommerce_app/core/services/services.dart';
import 'package:ecommerce_app/data/datasource/remote/address_data.dart';
import 'package:ecommerce_app/data/model/addressmodel.dart';
import 'package:get/get.dart';

import '../../core/class/statusrequest.dart';
import '../../core/functions/handlingdatacontroller.dart';
import '../../core/services/notification_service.dart';
import 'package:ecommerce_app/core/functions/session_guard.dart';

import 'package:ecommerce_app/core/logging/app_logger.dart';

class AddressViewController extends GetxController {
  NotificationService notificationService = Get.find<NotificationService>();

  AddressData addressData = AddressData(Get.find());

  List<AddressModel> data = [];
  StatusRequest statusRequest = StatusRequest.none;

  MyServices myServices = Get.find();

  deleteAddress(String addressid) async {
    statusRequest = StatusRequest.loading;
    update();
    var response = await addressData.deleteData(addressid);
    appDebugLog(
        "========================================Controller  $response");
    if (response != null && response['status'] == "success") {
      data.removeWhere((element) => element.addressId.toString() == addressid);
      statusRequest = StatusRequest.success;
      appDebugLog(
          " تم حذف العنوان بنجاح، عدد العناوين المتبقية: ${data.length}");
      if (data.isEmpty) {
        statusRequest = StatusRequest.failure;
      }
    } else {
      statusRequest = StatusRequest.failure;
      appDebugLog("فشل في حذف العنوان");
    }
    update();
    notificationService.showSuccessNotification(
        title: "71".tr, message: "Done Success");
  }

  getData() async {
    final userId = await requireUserId(myServices);
    if (userId == null) {
      return;
    }
    statusRequest = StatusRequest.loading;
    var response = await addressData.getData(userId);

    await Future.delayed(const Duration(seconds: 2));

    appDebugLog(
        "========================================Controller  $response");

    statusRequest = handlingData(response);
    if (StatusRequest.success == statusRequest) {
      if (response['status'] == "success") {
        List listdata = response['data'];
        data.addAll(listdata.map((e) => AddressModel.fromJson(e)));
        if (data.isEmpty) {
          statusRequest = StatusRequest.failure;
        }
      } else {
        appDebugLog("+++++++++++ there is no data +++++++++++");
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
