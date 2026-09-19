import 'package:ecommerce_app/core/constant/routes.dart';
import 'package:ecommerce_app/core/network/api_exception.dart';
import 'package:ecommerce_app/core/network/status_request_mapper.dart';
import 'package:ecommerce_app/core/platform/platform_service.dart';
import 'package:ecommerce_app/core/services/services.dart';
import 'package:ecommerce_app/data/datasource/remote/storefront_customer_auth_data.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/cupertino.dart';
import 'package:get/get.dart';

import '../../core/class/statusrequest.dart';
import '../../core/functions/handlingdatacontroller.dart';
import '../../data/datasource/remote/auth/signup.dart';

import 'package:ecommerce_app/core/logging/app_logger.dart';

abstract class SignUpController extends GetxController {
  signUp();
  goToSignIn();
}

class SignUpControllerImp extends SignUpController {
  GlobalKey<FormState> formstate = GlobalKey<FormState>();
  MyServices myServices = Get.find();
  late TextEditingController email;
  late TextEditingController username;
  late TextEditingController phone;
  late TextEditingController password;

  StatusRequest statusRequest = StatusRequest.none;
  bool isShowPassword = true;

  showPassword() {
    isShowPassword = isShowPassword == true ? false : true;
    update();
  }

  SignUpData signupData = SignUpData(Get.find());

  List data = [];

  @override
  signUp() async {
    var formdata = formstate.currentState;
    if (formdata!.validate()) {
      if (PlatformService.instance.isInitialized) {
        await _platformSignUp();
        return;
      }

      statusRequest = StatusRequest.loading;
      update();
      var response = await signupData.postData(
          username.text, password.text, email.text, phone.text);
      await Future.delayed(const Duration(seconds: 3));
      appDebugLog(
          "========================================Controller $response==============");
      statusRequest = handlingData(response);
      if (StatusRequest.success == statusRequest) {
        if (response['status'] == "success") {
          // data.addAll(response['data']);

          Get.offNamed(AppRoute.verfiyCodeSignUp,
              arguments: {"email": email.text});
        } else {
          Get.defaultDialog(
              title: "44".tr,
              middleText:
                  "45".tr); // Warning   Phone Number Or Email Already Exists
          statusRequest = StatusRequest.serverfailuer;
        }
      }
      update();
      appDebugLog("valid");

      // Get.delete<SignUpControllerImp>(); //when i use routes in map not in get x this line is work to dispose the information in the text form filed after moving to another page And instead of it we use lazy.put()
    } else {
      appDebugLog("not valid");
    }
  }

  Future<void> _platformSignUp() async {
    final rawPassword = password.text;

    if (rawPassword.length < 8 ||
        !RegExp(r'[A-Za-z]').hasMatch(rawPassword) ||
        !RegExp(r'[0-9]').hasMatch(rawPassword)) {
      statusRequest = StatusRequest.failure;
      update();
      Get.defaultDialog(
        title: "44".tr,
        middleText: "Password must be at least 8 characters and include a letter and a number.",
      );
      return;
    }

    statusRequest = StatusRequest.loading;
    update();

    try {
      final session = await StorefrontCustomerAuthData().register(
        name: username.text,
        email: email.text,
        phone: phone.text,
        password: rawPassword,
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
        // Account creation must not fail because push subscription is unavailable.
      }

      statusRequest = StatusRequest.success;
      update();
      Get.offAllNamed(AppRoute.homepage);
    } catch (error) {
      statusRequest = statusRequestForError(error);
      update();

      if (error is ApiException && error.statusCode == 409) {
        Get.defaultDialog(
          title: "44".tr,
          middleText: "45".tr,
        );
      } else {
        Get.defaultDialog(
          title: "44".tr,
          middleText: "Unable to create the account right now.",
        );
      }
    }
  }

  @override
  goToSignIn() {
    Get.offNamed(AppRoute.login);
  }

  @override
  void onInit() {
    username = TextEditingController();
    phone = TextEditingController();
    email = TextEditingController();
    password = TextEditingController();
    super.onInit();
  }

  @override
  void dispose() {
    email.dispose();
    username.dispose();
    phone.dispose();
    password.dispose();
    super.dispose();
  }
}
