import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:get/get.dart';
import 'package:flutter_ringtone_player/flutter_ringtone_player.dart';

import '../../controller/orders/pending_controller.dart';

import 'package:ecommerce_app/core/logging/app_logger.dart';

requestPermissionNotification() async {
  FirebaseMessaging messaging = FirebaseMessaging.instance;

  NotificationSettings settings = await messaging.requestPermission(
    alert: true,
    announcement: false,
    badge: true,
    carPlay: false,
    criticalAlert: false,
    provisional: false,
    sound: true,
  );

  if (settings.authorizationStatus == AuthorizationStatus.authorized) {
    appDebugLog('User granted permission');
  } else if (settings.authorizationStatus == AuthorizationStatus.provisional) {
    appDebugLog('User granted provisional permission');
  } else {
    appDebugLog('User declined or has not accepted permission');
  }
}

void fcmconfig() {
  FirebaseMessaging.onMessage.listen((message) {
    final notification = message.notification;
    final title = notification?.title?.trim();
    final body = notification?.body?.trim();

    appDebugLog('Foreground FCM message: ${message.messageId ?? 'unknown'}');

    if (notification != null &&
        (title?.isNotEmpty == true || body?.isNotEmpty == true)) {
      FlutterRingtonePlayer().playNotification();
      Get.snackbar(
        title?.isNotEmpty == true ? title! : 'Notification',
        body?.isNotEmpty == true ? body! : '',
      );
    }

    refreshPageNotification(message.data);
  });
}

refreshPageNotification(data) {
  appDebugLog("============================page id==========================");
  appDebugLog(data['pageid']);
  appDebugLog(
      "============================page name==========================");
  appDebugLog(data['pagename']);
  appDebugLog(
      "============================Current Route========================");
  appDebugLog(Get.currentRoute);

  if (Get.currentRoute == "/orederspending" &&
      data['pagename'] == "refreshorderpending") {
    OrdersPendingController controller = Get.find();
    controller.refreshOrder();
  }
}

//firebase + streaming
//socket io
//Notification refresh
