import 'package:ecommerce_app/core/class/statusrequest.dart';
import 'package:ecommerce_app/core/constant/routes.dart';
import 'package:ecommerce_app/data/datasource/remote/forgetpassword/resetpassword.dart';
import 'package:flutter/cupertino.dart';
import 'package:get/get.dart';

import '../../core/functions/handlingdatacontroller.dart';

import 'package:ecommerce_app/core/logging/app_logger.dart';

abstract class ResetPasswordController extends GetxController {
  goToSuccessResetPassword();
}

class ResetPasswordControllerImp extends ResetPasswordController {
  GlobalKey<FormState> formstate = GlobalKey<FormState>();
  late TextEditingController password;
  late TextEditingController repassword;

  ResetPasswordData resetPasswordData = ResetPasswordData(Get.find());
  StatusRequest statusRequest = StatusRequest.none;
  String? email;

  @override
  goToSuccessResetPassword() async {
    if (password.text != repassword.text) {
      Get.defaultDialog(title: "44".tr, middleText: "49".tr); //not match
    }
    var formdata = formstate.currentState;
    if (formdata!.validate()) {
      statusRequest = StatusRequest.loading;
      update();
      var response = await resetPasswordData.postData(email!, password.text);
      await Future.delayed(const Duration(seconds: 3));
      appDebugLog(
          "========================================Controller $response=======================================");
      statusRequest = handlingData(response);
      if (StatusRequest.success == statusRequest) {
        if (response['status'] == "success") {
          Get.offNamed(AppRoute.successResetPassword,
              arguments: {"email": email});
        } else {
          Get.defaultDialog(
              title: "44".tr, middleText: "50".tr); // "Warning Try Again
          statusRequest = StatusRequest.failure;
        }
      }
      update();
      appDebugLog("valid");
    } else {
      appDebugLog("not valid");
    }
  }

  @override
  void onInit() {
    email = Get.arguments['email'];
    password = TextEditingController();
    repassword = TextEditingController();
    super.onInit();
  }

  @override
  void dispose() {
    password.dispose();
    repassword.dispose();
    super.dispose();
  }
}
