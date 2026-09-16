import 'package:ecommerce_app/binding/intiallbindings.dart';
import 'package:ecommerce_app/core/localization/changelocal.dart';
import 'package:ecommerce_app/core/localization/translation.dart';
import 'package:ecommerce_app/core/platform/platform_startup_gate.dart';
import 'package:ecommerce_app/core/services/NotificationService.dart';
import 'package:ecommerce_app/core/services/services.dart';
import 'package:ecommerce_app/routes.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';

Future<void> _prepareLegacyServices() async {
  if (!Get.isRegistered<NotificationService>()) {
    Get.put(NotificationService());
  }

  if (!Get.isRegistered<MyServices>()) {
    await initialServices();
  }
}

void main() {
  WidgetsFlutterBinding.ensureInitialized();

  // First Flutter frame is no longer blocked by Firebase,
  // SharedPreferences, or the remote platform bootstrap.
  runApp(
    const PlatformStartupGate(
      prepareApp: _prepareLegacyServices,
      child: MyApp(),
    ),
  );
}

class MyApp extends StatelessWidget {
  const MyApp({super.key});

  @override
  Widget build(BuildContext context) {
    final controller = Get.put(LocalController());

    return GetMaterialApp(
      translations: MyTranslation(),
      debugShowCheckedModeBanner: false,
      title: 'User App',
      locale: controller.language,
      theme: controller.appTheme,
      initialBinding: IntiallBindings(),
      getPages: routes,
    );
  }
}
