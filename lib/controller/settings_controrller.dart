import 'package:ecommerce_app/core/class/statusrequest.dart';
import 'package:ecommerce_app/core/constant/routes.dart';
import 'package:ecommerce_app/core/network/api_exception.dart';
import 'package:ecommerce_app/core/network/status_request_mapper.dart';
import 'package:ecommerce_app/core/platform/platform_service.dart';
import 'package:ecommerce_app/core/services/services.dart';
import 'package:ecommerce_app/data/datasource/remote/storefront_customer_auth_data.dart';
import 'package:ecommerce_app/data/datasource/remote/users_data.dart';
import 'package:ecommerce_app/data/model/usersmodel.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:get/get.dart';

import '../core/functions/handlingdatacontroller.dart';

class SettingsController extends GetxController {
  final MyServices myServices = Get.find();

  final List<UsersModel> data = [];
  final UsersData usersData = UsersData(Get.find());

  StatusRequest statusRequest = StatusRequest.none;

  bool get isSignedIn => myServices.hasValidSession;

  bool get isPlatformCustomer =>
      myServices.isPlatformCustomerSession &&
      PlatformService.instance.isInitialized;

  String get displayName =>
      myServices.sharedPreferences.getString('username')?.trim() ?? '';

  String get displayEmail =>
      myServices.sharedPreferences.getString('email')?.trim() ?? '';

  Future<void> logout() async {
    final userId = myServices.userId;

    try {
      await FirebaseMessaging.instance.unsubscribeFromTopic('users');

      if (userId != null) {
        await FirebaseMessaging.instance.unsubscribeFromTopic('users$userId');
      }
    } catch (_) {
      // Logout must continue even if push unsubscribe is unavailable.
    }

    if (isPlatformCustomer) {
      try {
        await StorefrontCustomerAuthData().logout();
      } catch (_) {
        // StorefrontCustomerAuthData clears the local secure session in
        // finally. A network failure must not trap the user in the UI.
      }
    }

    await myServices.clearUserSession();
    Get.offAllNamed(AppRoute.login);
  }

  void goToLogin() {
    Get.toNamed(AppRoute.login);
  }

  Future<void> getData() async {
    if (!isSignedIn) {
      statusRequest = StatusRequest.none;
      update();
      return;
    }

    if (isPlatformCustomer) {
      await _refreshPlatformProfile();
      return;
    }

    await _refreshLegacyProfile();
  }

  Future<void> _refreshPlatformProfile() async {
    statusRequest = StatusRequest.loading;
    update();

    try {
      final customer = await StorefrontCustomerAuthData().me();

      await myServices.savePlatformCustomerProfile(
        id: customer.id,
        name: customer.name,
        email: customer.email,
        phone: customer.phone,
      );

      statusRequest = StatusRequest.success;
    } catch (error) {
      if (error is ApiException && error.statusCode == 401) {
        await myServices.clearUserSession();
      }

      statusRequest = statusRequestForError(error);
    }

    update();
  }

  Future<void> _refreshLegacyProfile() async {
    statusRequest = StatusRequest.loading;
    update();

    final response = await usersData.getData();
    statusRequest = handlingData(response);

    if (StatusRequest.success == statusRequest) {
      if (response['status'] == 'success') {
        final dataList = response['data'] as List;
        data
          ..clear()
          ..addAll(
            dataList.map(
              (entry) => UsersModel.fromJson(entry),
            ),
          );
      } else {
        statusRequest = StatusRequest.failure;
      }
    }

    update();
  }

  @override
  void onInit() {
    if (isSignedIn) {
      getData();
    }

    super.onInit();
  }
}
