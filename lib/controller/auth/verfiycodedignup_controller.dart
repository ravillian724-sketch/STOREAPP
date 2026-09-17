import 'package:ecommerce_app/core/class/statusrequest.dart';
import 'package:ecommerce_app/core/constant/routes.dart';
import 'package:ecommerce_app/data/datasource/remote/auth/verfiycodesignup.dart';
import 'package:get/get.dart';

import '../../core/constant/color.dart';
import '../../core/functions/handlingdatacontroller.dart';
import '../../view/widget/dialogwarning.dart';

import 'package:ecommerce_app/core/logging/app_logger.dart';

abstract class VerfiyCodeSignUpController extends GetxController {
  checkCode();
  goToSuccesSignUp(String veriycodesignup);
}

class VerfiyCodeSignUpControllerImp extends VerfiyCodeSignUpController {
  VerfiycodeSignUpData verfiycodeSignUpData = VerfiycodeSignUpData(Get.find());

  String? email;

  StatusRequest statusRequest = StatusRequest.none;

  @override
  checkCode() {}
  @override
  goToSuccesSignUp(String veriycodesignup) async {
    statusRequest = StatusRequest.loading;
    update();
    var response = await verfiycodeSignUpData.postData(email!, veriycodesignup);
    await Future.delayed(const Duration(seconds: 3));
    appDebugLog(
        "========================================Controller $response==============");
    statusRequest = handlingData(response);
    if (StatusRequest.success == statusRequest) {
      if (response['status'] == "success") {
        Get.offNamed(AppRoute.successSignUp);
      } else {
        // CustomFlashMessage.showMessage(message: "Vierfiy Code is'nt Correct");// "Warning  Verfiy Code Not Correct"
        DialogWarning(
          title: "44".tr,
          message: "46".tr,
          titleColor: AppColor.redDark,
          textColor: AppColor.redDark,
          buttonColor: AppColor.redDark,
        ).showWarningDialog();
      }
    }
    update();
    appDebugLog("valid");
  }

  @override
  void onInit() {
    email = Get.arguments['email'];
    super.onInit();
  }

  resend() {
    verfiycodeSignUpData.resendData(email!);
  }
}
