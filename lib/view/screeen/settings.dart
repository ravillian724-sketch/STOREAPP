import 'package:ecommerce_app/core/constant/color.dart';
import 'package:ecommerce_app/core/constant/imageasset.dart';
import 'package:ecommerce_app/core/constant/routes.dart';
import 'package:ecommerce_app/core/platform/platform_service.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import '../../controller/settings_controrller.dart';

class Settings extends StatelessWidget {
  const Settings({super.key});

  @override
  Widget build(BuildContext context) {
    Get.put(SettingsController());
    return Container(
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: [Colors.blue.shade50, Colors.white],
          stops: const [0.0, 0.3],
        ),
      ),
      child: ListView(
        physics: const BouncingScrollPhysics(),
        children: [
          Stack(
            clipBehavior: Clip.none,
            alignment: Alignment.center,
            children: [
              Container(
                height: Get.width / 2.5,
                decoration: BoxDecoration(
                  color: AppColor.primaryColor,
                  borderRadius: const BorderRadius.only(
                    bottomLeft: Radius.circular(30),
                    bottomRight: Radius.circular(30),
                  ),
                  boxShadow: [
                    BoxShadow(
                      color: AppColor.primaryColor.withValues(alpha: 0.3),
                      blurRadius: 10,
                      offset: const Offset(0, 5),
                    ),
                  ],
                ),
              ),
              Positioned(
                top: Get.width / 3.5,
                child: Container(
                  padding: const EdgeInsets.all(4),
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(100),
                    boxShadow: [
                      BoxShadow(
                        color: Colors.grey.withValues(alpha: 0.3),
                        blurRadius: 15,
                        spreadRadius: 2,
                      ),
                    ],
                  ),
                  child: const CircleAvatar(
                    radius: 50,
                    backgroundColor: Colors.white,
                    backgroundImage: AssetImage(AppImageAsset.oavatar),
                  ),
                ),
              ),
              const SizedBox(
                height: 2,
              ),
            ],
          ),
          const SizedBox(height: 80),
          GetBuilder<SettingsController>(
            builder: (controller) {
              if (!controller.isSignedIn) {
                return const SizedBox.shrink();
              }

              return Padding(
                padding: const EdgeInsets.fromLTRB(20, 0, 20, 14),
                child: Column(
                  children: [
                    if (controller.displayName.isNotEmpty)
                      Text(
                        controller.displayName,
                        textAlign: TextAlign.center,
                        style: const TextStyle(
                          fontSize: 20,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    if (controller.displayEmail.isNotEmpty) ...[
                      const SizedBox(height: 4),
                      Text(
                        controller.displayEmail,
                        textAlign: TextAlign.center,
                        style: const TextStyle(
                          color: Colors.black54,
                          fontSize: 14,
                        ),
                      ),
                    ],
                  ],
                ),
              );
            },
          ),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 15),
            child: Card(
              elevation: 5,
              shadowColor: Colors.blue.withValues(alpha: 0.2),
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(20),
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  // _buildSettingItem(
                  //   title: '108'.tr, // Disable Notifications
                  //   trailing: Switch(
                  //     value: true,
                  //     onChanged: (val) {},
                  //     activeColor: AppColor.primaryColor,
                  //   ),
                  // ),
                  // _buildDivider(),
                  _buildSettingItem(
                    onTap: () {
                      Get.toNamed(AppRoute.mdicinfo);
                    },
                    title: '157'.tr,
                    trailing: const Icon(Icons.medical_information_outlined,
                        color: AppColor.primaryColor, size: 30),
                  ),
                  _buildSettingItem(
                    onTap: () {
                      Get.toNamed(
                        PlatformService.instance.isInitialized
                            ? AppRoute.platformOrders
                            : AppRoute.orederspending,
                      );
                    },
                    title: '109'.tr, // Orders
                    trailing: const Icon(Icons.delivery_dining_outlined,
                        color: AppColor.primaryColor, size: 30),
                  ),
                  _buildDivider(),
                  if (!PlatformService.instance.isInitialized) ...[
                    _buildSettingItem(
                      onTap: () {
                        Get.toNamed(AppRoute.ordersarchive);
                      },
                      title: '110'.tr, // Archived Orders
                      trailing: const Icon(
                        Icons.archive_outlined,
                        color: AppColor.primaryColor,
                        size: 30,
                      ),
                    ),
                    _buildDivider(),
                    _buildSettingItem(
                      onTap: () {
                        Get.toNamed(AppRoute.rejectedorders);
                      },
                      title: "158".tr,
                      trailing: const Icon(
                        Icons.report_problem_outlined,
                        color: AppColor.primaryColor,
                        size: 30,
                      ),
                    ),
                    _buildDivider(),
                    _buildSettingItem(
                      onTap: () {
                        Get.toNamed(AppRoute.addressview);
                      },
                      title: '111'.tr, // Address
                      trailing: const Icon(
                        Icons.location_on_outlined,
                        color: AppColor.primaryColor,
                        size: 30,
                      ),
                    ),
                    _buildDivider(),
                  ],
                  _buildSettingItem(
                    title: '112'.tr,
                    trailing: const Icon(
                      Icons.help_outline_outlined,
                      color: AppColor.primaryColor,
                      size: 30,
                    ),
                    onTap: () {
                      final store = PlatformService.instance.storeConfig;
                      final isArabic = Get.locale?.languageCode == 'ar';
                      final configuredName =
                          isArabic ? store?.nameAr : store?.nameEn;
                      final storeName =
                          configuredName?.trim().isNotEmpty == true
                              ? configuredName!.trim()
                              : 'Pharmacy';

                      Get.defaultDialog(
                        title: '112'.tr,
                        contentPadding: const EdgeInsets.symmetric(
                          horizontal: 20,
                          vertical: 12,
                        ),
                        radius: 20,
                        content: Text(
                          isArabic
                              ? '$storeName منصة صيدلية رقمية تركز على تجربة تسوق صحية آمنة وسهلة وسريعة.'
                              : '$storeName is a digital pharmacy platform focused on a safe, simple, and fast health shopping experience.',
                          textAlign: TextAlign.center,
                          style: const TextStyle(fontSize: 16, height: 1.6),
                        ),
                        confirm: ElevatedButton(
                          onPressed: Get.back,
                          child: Text(isArabic ? 'إغلاق' : 'Close'),
                        ),
                      );
                    },
                  ),
                  _buildDivider(),
                  GetBuilder<SettingsController>(
                    builder: (controller) => _buildSettingItem(
                      title: controller.isSignedIn
                          ? "114".tr
                          : (Get.locale?.languageCode == 'ar'
                              ? 'تسجيل الدخول'
                              : 'Sign In'),
                      trailing: Icon(
                        controller.isSignedIn ? Icons.exit_to_app : Icons.login,
                        color: controller.isSignedIn
                            ? Colors.red
                            : AppColor.primaryColor,
                        size: 30,
                      ),
                      onTap: controller.isSignedIn
                          ? controller.logout
                          : controller.goToLogin,
                    ),
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 20),
          Center(
            child: Text(
              '© ${PlatformService.instance.storeConfig?.nameEn ?? 'Pharmacy'}',
              style: const TextStyle(
                color: AppColor.secondColor,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildSettingItem({
    required String title,
    required Widget trailing,
    VoidCallback? onTap,
  }) {
    return ListTile(
      contentPadding: const EdgeInsets.symmetric(horizontal: 20, vertical: 5),
      title: Text(
        title,
        style: const TextStyle(
          fontSize: 20,
          fontWeight: FontWeight.w500,
        ),
      ),
      trailing: trailing,
      onTap: onTap,
    );
  }

  Widget _buildDivider() {
    return Divider(
      height: 0.2,
      thickness: 2,
      color: Colors.grey.withValues(alpha: 0.1),
    );
  }
}
