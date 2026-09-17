import 'package:ecommerce_app/core/constant/apptheme.dart';
import 'package:ecommerce_app/core/platform/platform_service.dart';
import 'package:ecommerce_app/core/services/services.dart';
import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:get/get.dart';

import '../functions/fcmconfig.dart';
import '../services/notification_service.dart';

class LocalController extends GetxController {
  NotificationService notificationService = Get.find<NotificationService>();
  Locale? language;

  MyServices myServices = Get.find();

  ThemeData appTheme = themeEnglish;

  ThemeData _themeFor(String langCode) => AppTheme.build(
        isArabic: langCode == 'ar',
        store: PlatformService.instance.storeConfig,
      );

  void changeLang(String langCode) {
    final normalized = langCode == 'ar' ? 'ar' : 'en';
    final locale = Locale(normalized);
    myServices.sharedPreferences.setString('lang', normalized);
    appTheme = _themeFor(normalized);
    language = locale;
    Get.changeTheme(appTheme);
    Get.updateLocale(locale);
  }

  requestPerLocation() async {
    bool serviceEnabled;
    LocationPermission permission;
    serviceEnabled = await Geolocator.isLocationServiceEnabled();
    if (!serviceEnabled) {
      return notificationService.showErrorNotification(
          title: "71".tr, message: "Please Turn-on Location");
    }
    permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
      if (permission == LocationPermission.denied) {
        return notificationService.showErrorNotification(
            title: "71".tr,
            message: "Please Turn-on give permission ti access location");
      }
    }
    if (permission == LocationPermission.deniedForever) {
      // Permissions are denied forever, handle appropriately.
      return notificationService.showErrorNotification(
          title: "71".tr,
          message: "Can't use maps without give location permission ");
    }
  }

  @override
  void onInit() {
    // Message listeners are safe to configure at startup, but permission prompts
    // are intentionally deferred until the user enters the related feature.
    fcmconfig();

    final savedLanguage = myServices.sharedPreferences.getString('lang');
    final deviceLanguage = Get.deviceLocale?.languageCode;
    final languageCode = savedLanguage == 'ar' || savedLanguage == 'en'
        ? savedLanguage!
        : deviceLanguage == 'ar'
            ? 'ar'
            : 'en';

    language = Locale(languageCode);
    appTheme = _themeFor(languageCode);
    super.onInit();
  }
}
