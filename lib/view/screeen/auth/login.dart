import 'package:ecommerce_app/controller/auth/login_controller.dart';
import 'package:ecommerce_app/core/class/handlingdataview.dart';
import 'package:ecommerce_app/core/constant/color.dart';
import 'package:ecommerce_app/core/functions/alertexitapp.dart';
import 'package:ecommerce_app/core/functions/validinput.dart';
import 'package:ecommerce_app/view/widget/auth/coustomtextformauth.dart';
import 'package:ecommerce_app/view/widget/auth/custombuttomauth.dart';
import 'package:ecommerce_app/view/widget/auth/customtextbodyauth.dart';
import 'package:ecommerce_app/view/widget/auth/customtextsignup.dart';
import 'package:ecommerce_app/view/widget/auth/customtexttitelauth.dart';
import 'package:ecommerce_app/view/widget/auth/logoauth.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';

class Login extends StatelessWidget {
  const Login({super.key});

  @override
  Widget build(BuildContext context) {
    Get.lazyPut(() => LoginControllerImp());

    return Scaffold(
      backgroundColor: Colors.white,
      appBar: AppBar(
        backgroundColor: AppColor.backgroundCoor,
        elevation: 0,
        centerTitle: true,
        title: Text(
          "Sign In",
          style: Theme.of(context).textTheme.headlineLarge!.copyWith(
                color: AppColor.grey,
              ),
        ),
      ),
      body: GetBuilder<LoginControllerImp>(
        builder: (controller) {
          final bool isArabic =
              Get.locale?.languageCode.toLowerCase() == "ar";

          return PopScope(
            canPop: false,
            onPopInvokedWithResult: (bool didPop, bool? result) async {
              if (!didPop) {
                alertExitApp();
              }
            },
            child: HandlingDataRequest(
              statusRequest: controller.statusRequest,
              widget: Container(
                padding: const EdgeInsets.symmetric(
                  vertical: 10,
                  horizontal: 30,
                ),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: const BorderRadius.only(
                    topLeft: Radius.circular(30),
                    topRight: Radius.circular(30),
                  ),
                  boxShadow: [
                    BoxShadow(
                      color: Colors.grey.withOpacity(0.1),
                      spreadRadius: 1,
                      blurRadius: 5,
                      offset: const Offset(0, -3),
                    ),
                  ],
                ),
                child: Form(
                  key: controller.formstate,
                  child: ListView(
                    shrinkWrap: true,
                    children: [
                      const LogoAuth(),
                      CustomTextTitelAuth(text: "2".tr),
                      const SizedBox(height: 3),
                      CustomTextBodyAuth(bodyText: "3".tr),
                      const SizedBox(height: 12),

                      // Google suggestion
                      Container(
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                          color: const Color(0xfff8f9fa),
                          borderRadius: BorderRadius.circular(16),
                          border: Border.all(
                            color: Colors.grey.shade200,
                          ),
                        ),
                        child: Column(
                          children: [
                            Text(
                              isArabic
                                  ? "تسجيل أسرع وآمن"
                                  : "Faster and secure sign in",
                              style: const TextStyle(
                                fontWeight: FontWeight.w700,
                                fontSize: 14,
                              ),
                            ),
                            const SizedBox(height: 8),
                            SizedBox(
                              width: double.infinity,
                              height: 52,
                              child: OutlinedButton(
                                onPressed: controller.loginWithGoogle,
                                style: OutlinedButton.styleFrom(
                                  backgroundColor: Colors.white,
                                  foregroundColor: Colors.black87,
                                  side: BorderSide(
                                    color: Colors.grey.shade300,
                                  ),
                                  shape: RoundedRectangleBorder(
                                    borderRadius: BorderRadius.circular(14),
                                  ),
                                ),
                                child: Row(
                                  mainAxisAlignment:
                                      MainAxisAlignment.center,
                                  children: [
                                    Container(
                                      width: 28,
                                      height: 28,
                                      alignment: Alignment.center,
                                      decoration: BoxDecoration(
                                        color: Colors.white,
                                        shape: BoxShape.circle,
                                        border: Border.all(
                                          color: Colors.grey.shade300,
                                        ),
                                      ),
                                      child: const Text(
                                        "G",
                                        style: TextStyle(
                                          fontWeight: FontWeight.w900,
                                          fontSize: 17,
                                          color: Color(0xff4285F4),
                                        ),
                                      ),
                                    ),
                                    const SizedBox(width: 12),
                                    Text(
                                      isArabic
                                          ? "المتابعة باستخدام Google"
                                          : "Continue with Google",
                                      style: const TextStyle(
                                        fontSize: 15,
                                        fontWeight: FontWeight.w600,
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                            ),
                          ],
                        ),
                      ),

                      const SizedBox(height: 14),

                      Row(
                        children: [
                          Expanded(
                            child: Divider(
                              color: Colors.grey.shade300,
                            ),
                          ),
                          Padding(
                            padding: const EdgeInsets.symmetric(
                              horizontal: 12,
                            ),
                            child: Text(
                              isArabic ? "أو" : "OR",
                              style: TextStyle(
                                color: Colors.grey.shade600,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                          ),
                          Expanded(
                            child: Divider(
                              color: Colors.grey.shade300,
                            ),
                          ),
                        ],
                      ),

                      const SizedBox(height: 10),

                      CustomTextFormAuth(
                        isNumber: false,
                        valid: (val) {
                          return validInput(
                            val!,
                            5,
                            100,
                            "email",
                          );
                        },
                        hintText: "4".tr,
                        labelText: "Email",
                        iconDate: Icons.email_outlined,
                        mycontroller: controller.email,
                      ),

                      CustomTextFormAuth(
                        obscuretext: controller.isShowPassword,
                        onTapIcon: controller.showPassword,
                        isNumber: false,
                        valid: (val) {
                          return validInput(
                            val!,
                            5,
                            30,
                            "password",
                          );
                        },
                        hintText: "5".tr,
                        labelText: "Password",
                        iconDate: controller.isShowPassword
                            ? Icons.remove_red_eye_outlined
                            : Icons.lock_outlined,
                        mycontroller: controller.password,
                      ),

                      const SizedBox(height: 6),

                      Align(
                        alignment: AlignmentDirectional.centerStart,
                        child: InkWell(
                          onTap: controller.goToForgetPassword,
                          child: Padding(
                            padding: const EdgeInsets.symmetric(
                              vertical: 6,
                            ),
                            child: Text(
                              "7".tr,
                              style: const TextStyle(
                                color: AppColor.primaryColor,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                          ),
                        ),
                      ),

                      const SizedBox(height: 8),

                      CustomButtomAuth(
                        text: "6".tr,
                        onPressed: controller.login,
                      ),

                      const SizedBox(height: 14),

                      Center(
                        child: CustomTextSignUpOrSignIn(
                          textOne: "8".tr,
                          textTwo: "9".tr,
                          onTap: controller.goToSignUp,
                        ),
                      ),

                      const SizedBox(height: 20),
                    ],
                  ),
                ),
              ),
            ),
          );
        },
      ),
    );
  }
}
