import 'package:ecommerce_app/core/class/statusrequest.dart';
import 'package:ecommerce_app/core/constant/routes.dart';
import 'package:ecommerce_app/core/network/api_exception.dart';
import 'package:ecommerce_app/core/network/status_request_mapper.dart';
import 'package:ecommerce_app/core/platform/platform_service.dart';
import 'package:ecommerce_app/core/services/services.dart';
import 'package:ecommerce_app/data/datasource/remote/auth/login.dart';
import 'package:ecommerce_app/data/datasource/remote/storefront_customer_auth_data.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/cupertino.dart';
import 'package:get/get.dart';

import '../../core/functions/handlingdatacontroller.dart';

import 'package:ecommerce_app/core/logging/app_logger.dart';

abstract class LoginController extends GetxController {
  login();
  goToSignUp();
  goToForgetPassword();
}

class LoginControllerImp extends LoginController {
  LoginData loginData = LoginData(Get.find());
  GlobalKey<FormState> formstate = GlobalKey<FormState>();
  late TextEditingController email;
  late TextEditingController password;

  bool isShowPassword = true;
  MyServices myServices = Get.find();
  StatusRequest statusRequest = StatusRequest.none;
  showPassword() {
    isShowPassword = isShowPassword == true ? false : true;
    update();
  }

  @override
  login() async {
    var formdata = formstate.currentState;
    if (formdata!.validate()) {
      if (PlatformService.instance.isInitialized) {
        await _platformLogin();
        return;
      }

      statusRequest = StatusRequest.loading;
      update();
      var response = await loginData.postData(email.text, password.text);
      appDebugLog(
          "========================================Controller $response==============");
      statusRequest = handlingData(response);
      if (StatusRequest.success == statusRequest) {
        if (response['status'] == "success") {
          if (response['data']['users_approve'] == 1) {
            myServices.sharedPreferences
                .setString("id", response['data']['users_id'].toString());
            myServices.sharedPreferences
                .setString("username", response['data']['users_name']);
            myServices.sharedPreferences
                .setString("email", response['data']['users_email']);
            myServices.sharedPreferences
                .setString("phone", response['data']['users_phone']);
            myServices.sharedPreferences.setString("step", "2");

            String userid = response['data']['users_id'].toString();
            FirebaseMessaging.instance.subscribeToTopic("users");
            FirebaseMessaging.instance.subscribeToTopic("users$userid");
            Get.offAllNamed(AppRoute.homepage);
          } else {
            Get.toNamed(AppRoute.verfiyCodeSignUp,
                arguments: {"email": email.text});
          }
        } else {
          Get.defaultDialog(
              title: "44".tr,
              middleText: "47".tr); // "Warning  Email OR Password Not Correct"
          statusRequest = StatusRequest.failure;
        }
      }
      update();
      appDebugLog("valid");

      // Get.delete<SignUpControllerImp>(); //when i use routes in map not in get x this line is work to dispose the information in the text form filed after moving to another page And instead of it we use lazy.put()
    } else {
      appDebugLog("not valid");
    }
  }

  Future<void> _platformLogin() async {
    statusRequest = StatusRequest.loading;
    update();

    try {
      final session = await StorefrontCustomerAuthData().login(
        email: email.text,
        password: password.text,
      );

      await myServices.savePlatformCustomerProfile(
        id: session.customer.id,
        name: session.customer.name,
        email: session.customer.email,
        phone: session.customer.phone,
      );

      try {
        await FirebaseMessaging.instance.subscribeToTopic("users");
      } catch (_) {
        // Authentication must not fail because push subscription is unavailable.
      }

      statusRequest = StatusRequest.success;
      update();
      Get.offAllNamed(AppRoute.homepage);
    } catch (error) {
      statusRequest = statusRequestForError(error);
      update();

      if (error is ApiException && error.statusCode == 401) {
        Get.defaultDialog(
          title: "44".tr,
          middleText: "47".tr,
        );
      } else {
        Get.defaultDialog(
          title: "44".tr,
          middleText: "Unable to sign in right now.",
        );
      }
    }
  }

  @override
  goToSignUp() {
    Get.offNamed(AppRoute.signUp);
  }

  @override
  void onInit() {
    email = TextEditingController();
    password = TextEditingController();
    super.onInit();
  }

  @override
  void dispose() {
    email.dispose();
    password.dispose();
    super.dispose();
  }

  @override
  goToForgetPassword() {
    if (PlatformService.instance.isInitialized) {
      Get.defaultDialog(
        title: Get.locale?.languageCode == 'ar'
            ? 'استعادة كلمة المرور'
            : 'Password recovery',
        middleText: Get.locale?.languageCode == 'ar'
            ? 'استعادة كلمة المرور لحسابات المنصة ستُفعّل بعد ربط خدمة البريد الخاصة بالمتجر.'
            : 'Password recovery for platform accounts will be enabled after the store email service is connected.',
      );
      return;
    }

    Get.offNamed(AppRoute.forgetPassword);
  }
}
