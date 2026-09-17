import 'package:ecommerce_app/controller/onboardingcontroller.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import '../../../core/constant/color.dart';
import '../../../core/functions/responsivehelper.dart';
import '../../../data/datasource/static/static.dart';

class CoustomDotControllerOnBoarding extends StatelessWidget {
  const CoustomDotControllerOnBoarding({super.key});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<OnBoardingControllerImp>(
      builder: (controller) => Container(
        margin: EdgeInsets.only(bottom: Responsive.margin(30)),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            ...List.generate(
              onBoardingList.length,
              (index) => AnimatedContainer(
                margin: EdgeInsets.symmetric(horizontal: Responsive.margin(8)),
                duration: const Duration(milliseconds: 400),
                width: controller.currentPage == index
                    ? Responsive.w(50)
                    : Responsive.w(16),
                height: 8,
                decoration: BoxDecoration(
                  color: controller.currentPage == index
                      ? AppColor.primaryColor
                      : AppColor.primaryColor.withValues(alpha: 0.3),
                  borderRadius: BorderRadius.circular(Responsive.radius(20)),
                  boxShadow: controller.currentPage == index
                      ? [
                          BoxShadow(
                            color: AppColor.primaryColor.withValues(alpha: 0.3),
                            spreadRadius: 1,
                            blurRadius: 3,
                            offset: const Offset(0, 1),
                          )
                        ]
                      : null,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
