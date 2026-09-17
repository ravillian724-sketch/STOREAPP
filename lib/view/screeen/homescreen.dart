import 'package:ecommerce_app/controller/homescreen_controller.dart';
import 'package:ecommerce_app/core/constant/color.dart';
import 'package:ecommerce_app/core/constant/routes.dart';
import 'package:ecommerce_app/view/widget/home/custombottomappbarhome.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';

class HomeScreen extends StatelessWidget {
  const HomeScreen({super.key});

  void _showExitConfirmation() {
    if (Get.isDialogOpen ?? false) {
      return;
    }

    Get.defaultDialog(
      title: "40".tr,
      titleStyle: const TextStyle(
        fontWeight: FontWeight.bold,
        color: AppColor.redDark,
      ),
      middleText: "41".tr,
      onConfirm: () {
        Get.back();
        if (defaultTargetPlatform == TargetPlatform.android) {
          SystemNavigator.pop();
        }
      },
      onCancel: () {},
      cancelTextColor: AppColor.red,
      confirmTextColor: AppColor.primaryColor,
      buttonColor: AppColor.thirdColor,
    );
  }

  @override
  Widget build(BuildContext context) {
    Get.put(HomeScreenControllerImp());
    return GetBuilder<HomeScreenControllerImp>(
      builder: (controller) => Scaffold(
        floatingActionButton: FloatingActionButton(
          backgroundColor: AppColor.primaryColor,
          onPressed: () {
            Get.toNamed(AppRoute.cart);
          },
          child: const Icon(Icons.shopping_basket_outlined),
        ),
        floatingActionButtonLocation: FloatingActionButtonLocation.centerDocked,
        bottomNavigationBar: const CustomBottomAppBarHome(),
        body: PopScope(
          canPop: false,
          onPopInvokedWithResult: (didPop, result) {
            if (!didPop) {
              _showExitConfirmation();
            }
          },
          child: controller.listPage.elementAt(controller.currentPage),
        ),
      ),
    );
  }
}
