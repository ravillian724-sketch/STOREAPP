import 'package:ecommerce_app/controller/auth/signup_contrller.dart';
import 'package:ecommerce_app/core/constant/color.dart';
import 'package:ecommerce_app/core/functions/validinput.dart';
import 'package:ecommerce_app/core/platform/platform_service.dart';
import 'package:ecommerce_app/view/widget/auth/coustomtextformauth.dart';
import 'package:ecommerce_app/view/widget/auth/customtextbodyauth.dart';
import 'package:ecommerce_app/view/widget/auth/customtexttitelauth.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../../core/class/handlingdataview.dart';
import '../../widget/auth/custombuttomauth.dart';
import '../../widget/auth/customtextsignup.dart';

class SignUp extends StatelessWidget {
  const SignUp({super.key});

  @override
  Widget build(BuildContext context) {
    Get.lazyPut(() => SignUpControllerImp());
    // SignUpControllerImp controller = Get.put(SignUpControllerImp()); // this is if i want to use Get.delete instead of lazy put
    return Scaffold(
      backgroundColor: Colors.white,
      appBar: AppBar(
        backgroundColor: AppColor.backgroundCoor,
        elevation: 0.0,
        centerTitle: true,
        title: Text(
          "Sign Up",
          style: Theme.of(context)
              .textTheme
              .headlineLarge!
              .copyWith(color: AppColor.grey),
        ),
      ),
      body: GetBuilder<SignUpControllerImp>(
        builder: (controller) => PopScope(
          canPop: true,
          child: GetBuilder<SignUpControllerImp>(
            builder:
                (controller) => //wee add this builder for adding loading animation

                    HandlingDataRequest(
              statusRequest: controller.statusRequest,
              widget: Container(
                //if want to use get.delete should don't use getBuilder
                padding:
                    const EdgeInsets.symmetric(vertical: 10, horizontal: 30),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: const BorderRadius.only(
                    topLeft: Radius.circular(30),
                    topRight: Radius.circular(30),
                  ),
                  boxShadow: [
                    BoxShadow(
                      color: Colors.grey.withValues(alpha: 0.1),
                      spreadRadius: 1,
                      blurRadius: 5,
                      offset: const Offset(0, -3),
                    ),
                  ],
                ),
                child: Form(
                  key: controller.formstate,
                  child: ListView(
                    children: [
                      CustomTextTitelAuth(
                        text: '2'.tr,
                      ), //"Welcome Back"
                      const SizedBox(
                        height: 5,
                      ),
                      CustomTextBodyAuth(
                          bodyText: "18"
                              .tr), //"sign Up using email and password or  social media"
                      const SizedBox(
                        height: 10,
                      ),
                      CustomTextFormAuth(
                        isNumber: false,
                        valid: (val) {
                          if (PlatformService.instance.isInitialized) {
                            final value = val?.trim() ?? '';

                            if (value.length < 2) {
                              return Get.locale?.languageCode == 'ar'
                                  ? 'الاسم يجب أن يكون حرفين على الأقل.'
                                  : 'Name must be at least 2 characters.';
                            }

                            if (value.length > 100) {
                              return Get.locale?.languageCode == 'ar'
                                  ? 'الاسم طويل جدًا.'
                                  : 'Name is too long.';
                            }

                            return null;
                          }

                          return validInput(val!, 3, 8, "username");
                        },
                        hintText: "16".tr, //"Enter Your Username"
                        labelText: PlatformService.instance.isInitialized
                            ? (Get.locale?.languageCode == 'ar'
                                ? 'الاسم'
                                : 'Name')
                            : "UserName",
                        iconDate: Icons.person_outline,
                        mycontroller: controller.username,
                      ),
                      CustomTextFormAuth(
                        isNumber: false,
                        valid: (val) {
                          return validInput(val!, 5, 100, "email");
                        },
                        hintText: "4".tr, //"Enter Your Email"
                        labelText: "Email",
                        iconDate: Icons.email_outlined,
                        mycontroller: controller.email,
                      ),
                      CustomTextFormAuth(
                        isNumber: true,
                        valid: (val) {
                          if (PlatformService.instance.isInitialized &&
                              (val?.trim().isEmpty ?? true)) {
                            return null;
                          }

                          return validInput(val!, 8, 20, "phone");
                        },
                        hintText: "17".tr, // "Enter Your Phone"
                        labelText: Get.locale?.languageCode == 'ar'
                            ? 'الجوال'
                            : 'Phone',
                        iconDate: Icons.phone_outlined,
                        mycontroller: controller.phone,
                      ),
                      GetBuilder<SignUpControllerImp>(
                        builder: (controller) => CustomTextFormAuth(
                          obscuretext: controller.isShowPassword,
                          onTapIcon: () {
                            controller.showPassword();
                          },
                          isNumber: false,
                          valid: (val) {
                            if (PlatformService.instance.isInitialized) {
                              final value = val ?? '';

                              if (value.length < 8 ||
                                  !RegExp(r'[A-Za-z]').hasMatch(value) ||
                                  !RegExp(r'[0-9]').hasMatch(value)) {
                                return Get.locale?.languageCode == 'ar'
                                    ? '8 أحرف على الأقل وتتضمن حرفًا ورقمًا.'
                                    : 'Use 8+ characters with a letter and a number.';
                              }

                              return null;
                            }

                            return validInput(val!, 5, 30, "password");
                          },
                          hintText: "5".tr, //"Enter Your Password"
                          labelText: Get.locale?.languageCode == 'ar'
                              ? 'كلمة المرور'
                              : 'Password',
                          iconDate: controller.isShowPassword == true
                              ? Icons.remove_red_eye_outlined
                              : Icons.lock_outlined,
                          mycontroller: controller.password,
                        ),
                      ),
                      const SizedBox(
                        height: 15,
                      ),
                      CustomButtomAuth(
                        text: "9".tr, //"Sign In"
                        onPressed: () {
                          controller.signUp();
                        },
                      ),
                      const SizedBox(
                        height: 10,
                      ),
                      CustomTextSignUpOrSignIn(
                        textOne: "19".tr, //" Do you have an account ?"
                        textTwo: "20".tr, //" Sign In",
                        onTap: () {
                          controller.goToSignIn();
                        },
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
