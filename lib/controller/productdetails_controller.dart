import 'package:ecommerce_app/core/services/notification_service.dart';
import 'package:ecommerce_app/data/model/itemsmodel.dart';
import 'package:get/get.dart';

import '../core/class/statusrequest.dart';
import '../core/functions/handlingdatacontroller.dart';
import '../core/services/services.dart';
import '../data/datasource/remote/cart_data.dart';
import 'package:ecommerce_app/core/functions/session_guard.dart';

import 'package:ecommerce_app/core/logging/app_logger.dart';

abstract class ProductdetailsController extends GetxController {}

class ProductDetailsControllerImp extends ProductdetailsController {
//CartController cartController = Get.put(CartController());
  NotificationService notificationService = Get.find<NotificationService>();
  CartData cartData = CartData(Get.find());
  MyServices myServices = Get.find();

  late ItemsModel itemsModel;
  StatusRequest statusRequest = StatusRequest.none;
  int countitems = 0;

  initialData() async {
    statusRequest = StatusRequest.loading;
    itemsModel = Get.arguments['itemsModel'];
    countitems = await getCountItems(itemsModel.itemsId!.toString());
    statusRequest = StatusRequest.success;
    update();
  }

  getCountItems(String itemsid) async {
    final userId = await requireUserId(myServices);
    if (userId == null) {
      return 0;
    }
    statusRequest = StatusRequest.loading;
    var response = await cartData.getCountCart(userId, itemsid);
    appDebugLog(
        "========================================Controller  $response");
    statusRequest = handlingData(response);
    // Start Backend
    if (StatusRequest.success == statusRequest) {
      if (response['status'] == "success") {
        int countitems = 0;
        countitems = response['data'];
        appDebugLog("=========== counted items=============");
        appDebugLog("$countitems");
        return countitems;
        // data.addAll(response['data']);
      } else {
        statusRequest = StatusRequest.failure;
      }
    }
  }

  addItems(String itemsid) async {
    final userId = await requireUserId(myServices);
    if (userId == null) {
      return;
    }
    update();
    statusRequest = StatusRequest.loading;
    var response = await cartData.addCart(userId, itemsid);
    appDebugLog(
        "========================================Controller  $response");
    statusRequest = handlingData(response);
    // Start Backend
    if (StatusRequest.success == statusRequest) {
      if (response['status'] == "success") {
        notificationService.showSuccessNotification(
            title: "71".tr,
            message: "73".tr); //notification // done adding product to cart
        // data.addAll(response['data']);
      } else {
        statusRequest = StatusRequest.failure;
      }
    }
    update();
    //End
  }

  deleteItems(String itemsid) async {
    final userId = await requireUserId(myServices);
    if (userId == null) {
      return;
    }
    update();
    statusRequest = StatusRequest.loading;
    var response = await cartData.deleteCart(userId, itemsid);
    appDebugLog(
        "========================================Controller  $response");
    statusRequest = handlingData(response);
    // Start Backend
    if (StatusRequest.success == statusRequest) {
      if (response['status'] == "success") {
        notificationService.showErrorNotification(
            title: "71".tr,
            message: "74".tr); //notification // done adding product from cart
        // data.addAll(response['data']);
      } else {
        statusRequest = StatusRequest.failure;
      }
    }
    update();
    //End
  }

  List subitems = [
    {
      "name": "65".tr,
      "id": 1,
      "active": 0,
    },
    {
      "name": "67".tr,
      "id": 2,
      "active": 1,
    },
    {
      "name": "66".tr,
      "id": 3,
      "active": 0,
    },
  ];

  add() {
    addItems(itemsModel.itemsId!.toString());
    countitems++;
    update();
  }

  remove() {
    deleteItems(itemsModel.itemsId!.toString());
    appDebugLog("removing");
    if (countitems > 0) {
      countitems--;
      update();
    }
  }

  @override
  void onInit() {
    initialData();
    super.onInit();
  }
}
