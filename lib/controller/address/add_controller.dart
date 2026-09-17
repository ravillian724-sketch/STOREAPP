import 'dart:async';

import 'package:ecommerce_app/core/class/statusrequest.dart';
import 'package:geolocator/geolocator.dart';
import 'package:get/get.dart';
import 'package:google_maps_flutter/google_maps_flutter.dart';

import '../../core/constant/routes.dart';

class AdDAddressController extends GetxController {
  List<Marker> markers = [];

  double? lat;
  double? long;
  StatusRequest statusRequest = StatusRequest.loading;

  final Completer<GoogleMapController> completerController =
      Completer<GoogleMapController>();

  Position? position;

  CameraPosition? kGooglePlex;

  addMarkers(LatLng latLng) {
    markers.clear();
    markers.add(Marker(markerId: const MarkerId("1"), position: latLng));
    lat = latLng.latitude;
    long = latLng.longitude;
    update();
  }

  void goToPageAddDetailsAddress() {
    final currentLat = lat;
    final currentLong = long;

    if (currentLat == null || currentLong == null) {
      return;
    }

    Get.toNamed(
      AppRoute.addressadddetails,
      arguments: {
        "lat": currentLat.toString(),
        "long": currentLong.toString(),
      },
    );
  }

  Future<void> getCurrentLocation() async {
    try {
      statusRequest = StatusRequest.loading;
      update();

      final serviceEnabled = await Geolocator.isLocationServiceEnabled();
      if (!serviceEnabled) {
        statusRequest = StatusRequest.failure;
        update();
        return;
      }

      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }

      if (permission == LocationPermission.denied ||
          permission == LocationPermission.deniedForever) {
        statusRequest = StatusRequest.failure;
        update();
        return;
      }

      final currentPosition = await Geolocator.getCurrentPosition();

      position = currentPosition;

      kGooglePlex = CameraPosition(
        target: LatLng(
          currentPosition.latitude,
          currentPosition.longitude,
        ),
        zoom: 14.4746,
      );

      addMarkers(
        LatLng(
          currentPosition.latitude,
          currentPosition.longitude,
        ),
      );

      statusRequest = StatusRequest.none;
    } catch (_) {
      position = null;
      kGooglePlex = null;
      statusRequest = StatusRequest.failure;
    }

    update();
  }

  @override
  void onInit() {
    getCurrentLocation();
    super.onInit();
  }
}
