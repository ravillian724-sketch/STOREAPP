import 'package:ecommerce_app/core/services/notification_service.dart';
import 'package:get/get.dart';
import '../core/class/statusrequest.dart';
import '../core/functions/handlingdatacontroller.dart';
import '../core/services/services.dart';
import '../data/datasource/remote/myfavorite_data.dart';
import '../data/model/myfavorite.dart';
import 'package:ecommerce_app/core/functions/session_guard.dart';

import 'package:ecommerce_app/core/logging/app_logger.dart';

class MyFavoriteController extends GetxController {
  MyFavoriteData favoriteData = MyFavoriteData(Get.find());
  NotificationService notificationService = Get.find<NotificationService>();

  List<MyFavoriteModel> data = [];
  StatusRequest statusRequest = StatusRequest.none;

  MyServices myServices = Get.find();

  getData() async {
    final userId = await requireUserId(myServices);
    if (userId == null) {
      return;
    }
    data.clear();
    statusRequest = StatusRequest.loading;
    update();

    var response = await favoriteData.getData(userId);
    appDebugLog(
        "========================================Controller  $response");
    statusRequest = handlingData(response);
    // Start Backend
    if (StatusRequest.success == statusRequest) {
      if (response['status'] == "success") {
        List responsedata = response['data'];
        data.addAll(responsedata.map((e) => MyFavoriteModel.fromJson(e)));
        appDebugLog("data");
        appDebugLog(data);
      } else {
        statusRequest = StatusRequest.failure;
      }
    }
    //End
    update();
  }

  deleteFromFavorite(String favoriteid) async {
    // تغيير الحالة إلى تحميل
    statusRequest = StatusRequest.loading;
    update();
    var response = await favoriteData.deleteData(favoriteid);
    appDebugLog(
        "========================================Controller  $response");
    if (response != null && response['status'] == "success") {
      // حذف  من القائمة
      data.removeWhere(
          (element) => element.favoriteId.toString() == favoriteid);
      statusRequest = StatusRequest.success;
      appDebugLog("Done deleted the remain items ${data.length}");
    } else {
      // في حالة فشل
      statusRequest = StatusRequest.failure;
      appDebugLog("فشل في حذف العنصر");
    }

    // تحديث الواجهة
    update();
    notificationService.showErrorNotification(
        title: "71".tr, message: "Item has been deleted successfully");
  }

  @override
  void onInit() {
    getData();
    super.onInit();
  }
}
