import 'package:ecommerce_app/binding/intiallbindings.dart';
import 'package:ecommerce_app/core/localization/changelocal.dart';
import 'package:ecommerce_app/core/localization/translation.dart';
import 'package:ecommerce_app/core/platform/platform_startup_gate.dart';
import 'package:ecommerce_app/core/services/NotificationService.dart';
import 'package:ecommerce_app/core/services/services.dart';
import 'package:ecommerce_app/routes.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Critical legacy services still required before the existing application
  // starts. These will be migrated behind explicit platform contracts later.
  Get.put(NotificationService());
  await initialServices();

  // PlatformStartupGate is the migration boundary between the existing
  // application and STOREAPP's multi-tenant platform bootstrap.
  runApp(
    const PlatformStartupGate(
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
