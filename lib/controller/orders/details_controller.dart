import 'dart:async';

import 'package:ecommerce_app/data/datasource/remote/orders/details_data.dart';
import 'package:get/get.dart';
import 'package:google_maps_flutter/google_maps_flutter.dart';

import '../../core/class/statusrequest.dart';
import '../../core/functions/handlingdatacontroller.dart';
import '../../data/model/cartmodel.dart';
import '../../data/model/ordersmodel.dart';

import 'package:ecommerce_app/core/logging/app_logger.dart';

class OrdersDetailsController extends GetxController {
  OrdersDetailsData ordersDetailsData = OrdersDetailsData(Get.find());

  List<CartModel> data = [];
  late StatusRequest statusRequest;

  List<Marker> markers = [];

  double? lat;
  double? long;
  final Completer<GoogleMapController> completerController =
      Completer<GoogleMapController>();
  CameraPosition? cameraPosition;

  late OrdersModel ordersModel;

  initialData() {
    if (ordersModel.ordersType == "0") {
      cameraPosition = CameraPosition(
        target: LatLng(
            double.parse(ordersModel.addressLat!.toString()),
            double.parse(ordersModel.addressLong!
                .toString())), //the lat and the lang is double from DB but i parse it to string then to double in case there error
        zoom: 12.4746,
      );
      markers.add(Marker(
          markerId: const MarkerId("1"),
          position: LatLng(double.parse(ordersModel.addressLat!.toString()),
              double.parse(ordersModel.addressLong!.toString()))));
    }
  }

  getData() async {
    statusRequest = StatusRequest.loading;
    var response =
        await ordersDetailsData.getData(ordersModel.ordersId!.toString());
    appDebugLog(
        "========================================Controller  $response");
    statusRequest = handlingData(response);
    if (StatusRequest.success == statusRequest) {
      if (response['status'] == "success") {
        List listdata = response['data'];
        data.addAll(listdata.map((e) => CartModel.fromJson(e)));
      } else {
        statusRequest = StatusRequest.failure;
      }
    }
    update();
  }

  @override
  void onInit() {
    ordersModel = Get.arguments["ordersmodel"];
    initialData();
    getData();
    super.onInit();
  }
}
