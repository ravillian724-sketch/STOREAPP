import 'package:ecommerce_app/core/class/statusrequest.dart';
import 'package:ecommerce_app/core/constant/routes.dart';
import 'package:ecommerce_app/core/services/services.dart';
import 'package:ecommerce_app/core/platform/platform_service.dart';
import 'package:ecommerce_app/data/datasource/remote/home_data.dart';
import 'package:ecommerce_app/data/model/itemsmodel.dart';
import 'package:flutter/cupertino.dart';
import 'package:get/get.dart';

import '../core/functions/handlingdatacontroller.dart';

import 'package:ecommerce_app/core/logging/app_logger.dart';

abstract class HomeController extends SearchMixController {
  initialData();
  getData();
  goToItems(List categories, selectedCat, String categoryid);
  goToSubCategories(List categories, int selectedCat, String categoryid);
}

class HomeControllerImp extends HomeController {
  MyServices myServices = Get.find();

  String? username;
  String? id;
  String? lang;

  String titelhomeCard = '';
  String bodyhomeCard = '';
  String deliverytime = '';

  List data = [];
  List categories = [];
  List items = [];

  List settingsdata = [];

  @override
  initialData() {
    lang = myServices.sharedPreferences.getString("lang");
    username = myServices.sharedPreferences.getString("username");
    id = myServices.sharedPreferences.getString("id");
  }

  @override
  getData() async {
    statusRequest = StatusRequest.loading;
    update();

    final response = await homeData.getData();
    appDebugLog(
      "========================================Controller  $response",
    );

    statusRequest = handlingData(response);

    if (StatusRequest.success == statusRequest) {
      if (response['status'] == "success") {
        categories
          ..clear()
          ..addAll(response['categories']['data']);
        items
          ..clear()
          ..addAll(response['items']['data'] as Iterable);
        settingsdata
          ..clear()
          ..addAll(response['settings']['data']);

        final store = PlatformService.instance.storeConfig;
        final isArabic = lang == "ar";

        if (settingsdata.isNotEmpty) {
          titelhomeCard =
              settingsdata.first['settings_titel']?.toString() ?? '';
          bodyhomeCard = settingsdata.first['settings_body']?.toString() ?? '';

          final configuredDelivery =
              settingsdata.first['settings_deliverytime']?.toString();

          if (configuredDelivery != null &&
              configuredDelivery.trim().isNotEmpty) {
            myServices.sharedPreferences.setString(
              "deliverytime",
              configuredDelivery,
            );
          }
        } else if (store != null) {
          titelhomeCard = isArabic ? store.nameAr : store.nameEn;
          bodyhomeCard = isArabic
              ? "أسعار وتوفر محدثان من الفرع"
              : "Current pricing and branch availability";
        }
      } else {
        statusRequest = StatusRequest.failure;
      }
    }

    update();
  }

  goToPageProductDetails(itemsModel) {
    Get.toNamed("productdetails", arguments: {"itemsModel": itemsModel});
  }

  @override
  goToItems(categories, selectedCat, categoryid) {
    Get.toNamed(
      AppRoute.items,
      arguments: {
        "categories": categories,
        "selectedCat": selectedCat,
        "categoryid": categoryid,
      },
    );
    appDebugLog("success go to selected=======");
  }

  @override
  goToSubCategories(categories, selectedCat, categoryid) {
    Get.toNamed(
      AppRoute.home2,
      arguments: {
        "categories": categories,
        "selectedCat": selectedCat,
        "categoryid": categoryid,
      },
    );
    appDebugLog("success go to selected====================================");
  }

  @override
  void onInit() {
    search = TextEditingController();
    initialData();
    getData();
    super.onInit();
  }
}

//========================

class SearchMixController extends GetxController {
  List<ItemsModel> listdata = [];
  HomeData homeData = HomeData();
  StatusRequest statusRequest = StatusRequest.none;
  bool isSearch = false;
  TextEditingController? search;
  checkSearch(val) {
    if (val == "") {
      statusRequest = StatusRequest.none;
      isSearch = false;
    }
    update();
  }

  onSearchItems() {
    if (search!.text.isEmpty) {
      isSearch = false;
      listdata.clear();
      update();
      return;
    }
    isSearch = true;
    listdata.clear();
    searchData();
    update();
  }

  searchData() async {
    statusRequest = StatusRequest.loading;
    var response = await homeData.searchData(search!.text);
    // await Future.delayed(const Duration(seconds: 3));
    appDebugLog(
        "========================================Controller  $response");
    statusRequest = handlingData(response);
    if (StatusRequest.success == statusRequest) {
      if (response['status'] == "success") {
        List resbosedata = response['data'];
        listdata.addAll(resbosedata.map((e) => ItemsModel.fromJson(e)));
      } else {
        statusRequest = StatusRequest.failure;
      }
    }
    update();
  }
}
