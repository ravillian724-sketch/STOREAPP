import 'dart:convert';
import 'package:cloud_firestore/cloud_firestore.dart';
import 'package:get/get.dart';
import 'package:google_maps_flutter/google_maps_flutter.dart';
import 'package:http/http.dart' as http;

import '../../core/class/statusrequest.dart';
import '../../core/constant/color.dart';
import '../../core/services/services.dart';
import '../../data/model/ordersmodel.dart';

import 'package:ecommerce_app/core/logging/app_logger.dart';

class TrackingController extends GetxController {
  List<Marker> markers = [];
  Set<Polyline> polylineSet2 = {};
  GoogleMapController? gmc;
  CameraPosition? cameraPosition;
  MyServices myServices = Get.find();
  StatusRequest statusRequest = StatusRequest.success;
  late OrdersModel ordersModel;

  double? destlat;
  double? destlong;
  double? currentlat;
  double? currentlong;

  String distanceText = "";
  String durationText = "";

  double? routeDistance; // meters
  double? routeDuration; // seconds

  bool isMapReady = false;

  String? lastPolylineKey; // لتجنب تكرار رسم نفس المسار

  @override
  void onInit() {
    ordersModel = Get.arguments["ordersmodel"];
    intialData();
    getLocationDelivery();
    super.onInit();
  }

  void intialData() {
    destlat = double.tryParse(ordersModel.addressLat.toString());
    destlong = double.tryParse(ordersModel.addressLong.toString());

    cameraPosition = CameraPosition(
      target: LatLng(destlat!, destlong!),
      zoom: 12.5,
    );

    markers.add(
      Marker(
        markerId: const MarkerId("dest"),
        position: LatLng(destlat!, destlong!),
        infoWindow: const InfoWindow(title: "الوجهة"),
      ),
    );
  }

  void getLocationDelivery() {
    appDebugLog("✅ بدء الاستماع لموقع عامل التوصيل");

    FirebaseFirestore.instance
        .collection("delivery")
        .doc(ordersModel.ordersId.toString())
        .snapshots()
        .listen((event) {
      if (event.exists) {
        double newLat = event.get("lat");
        double newLong = event.get("long");

        currentlat = newLat;
        currentlong = newLong;

        updateMarkerDelivery(newLat, newLong);

        // لا ترسم المسار إذا لم يتغير الموقع
        final newKey = "$newLat,$newLong";
        if (lastPolylineKey != newKey) {
          lastPolylineKey = newKey;
          initPolyLine();
        }
      }
    });
  }

  void updateMarkerDelivery(double newlat, double newlong) {
    markers.removeWhere((element) => element.markerId.value == "current");

    markers.add(
      Marker(
        markerId: const MarkerId("current"),
        position: LatLng(newlat, newlong),
        infoWindow: const InfoWindow(title: "الموقع الحالي"),
        icon: BitmapDescriptor.defaultMarkerWithHue(BitmapDescriptor.hueBlue),
      ),
    );

    update();
  }

  Future<void> initPolyLine() async {
    if (currentlat == null ||
        currentlong == null ||
        destlat == null ||
        destlong == null) {
      appDebugLog("❌ بيانات المواقع غير مكتملة");
      return;
    }

    final url =
        "https://router.project-osrm.org/route/v1/driving/$currentlong,$currentlat;$destlong,$destlat?overview=full&geometries=geojson";

    try {
      final response = await http.get(Uri.parse(url));

      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);

        if (data['routes'].isNotEmpty) {
          final route = data['routes'][0];
          routeDistance = (route['distance'] as num).toDouble();
          routeDuration = (route['duration'] as num).toDouble() + 600;

          distanceText = "${(routeDistance! / 1000).toStringAsFixed(2)} كم";
          durationText = "${(routeDuration! / 60).toStringAsFixed(0)} دقيقة";

          List<LatLng> polylineco = [];
          final coords = route['geometry']['coordinates'];
          for (var coord in coords) {
            double lon = coord[0];
            double lat = coord[1];
            polylineco.add(LatLng(lat, lon));
          }

          // تحديث البوليلاين بمجموعة جديدة
          polylineSet2 = {
            Polyline(
              polylineId: const PolylineId("route_osrm"),
              color: AppColor.red,
              width: 5,
              points: polylineco,
            ),
          };

          update();

          // تحريك الكاميرا عند أول مرة
          if (gmc != null) {
            final bounds = _getLatLngBounds(
              LatLng(currentlat!, currentlong!),
              LatLng(destlat!, destlong!),
            );

            Future.delayed(const Duration(milliseconds: 100), () {
              gmc!.animateCamera(CameraUpdate.newLatLngBounds(bounds, 70));
            });
          }
        } else {
          appDebugLog("❌ لا توجد مسارات من OSRM.");
        }
      } else {
        appDebugLog("❌ فشل تحميل المسار. كود: ${response.statusCode}");
      }
    } catch (e) {
      appDebugLog("❌ خطأ في جلب المسار: $e");
    }
  }

  LatLngBounds _getLatLngBounds(LatLng pos1, LatLng pos2) {
    double southWestLat =
        pos1.latitude < pos2.latitude ? pos1.latitude : pos2.latitude;
    double southWestLng =
        pos1.longitude < pos2.longitude ? pos1.longitude : pos2.longitude;

    double northEastLat =
        pos1.latitude > pos2.latitude ? pos1.latitude : pos2.latitude;
    double northEastLng =
        pos1.longitude > pos2.longitude ? pos1.longitude : pos2.longitude;

    return LatLngBounds(
      southwest: LatLng(southWestLat, southWestLng),
      northeast: LatLng(northEastLat, northEastLng),
    );
  }

  @override
  void onClose() {
    appDebugLog("🛑 تم إغلاق التتبع");
    gmc?.dispose();
    super.onClose();
  }
}
