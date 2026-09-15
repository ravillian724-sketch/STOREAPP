import 'package:firebase_core/firebase_core.dart';
import 'package:get/get.dart';
import 'package:shared_preferences/shared_preferences.dart';

class MyServices extends GetxService {
  late SharedPreferences sharedPreferences;

  Future<MyServices> init() async {
    await Firebase.initializeApp();
    sharedPreferences = await SharedPreferences.getInstance();
    return this;
  }

  /// Returns a valid local MySQL user id, or null if the session is invalid.
  String? get userId {
    final value = sharedPreferences.getString("id")?.trim();

    if (value == null || value.isEmpty) {
      return null;
    }

    return value;
  }

  bool get hasValidSession {
    return sharedPreferences.getString("step") == "2" &&
        userId != null;
  }

  Future<void> clearUserSession() async {
    const sessionKeys = <String>[
      "id",
      "username",
      "email",
      "phone",
      "step",
      "auth_provider",
    ];

    for (final key in sessionKeys) {
      await sharedPreferences.remove(key);
    }
  }
}

Future<void> initialServices() async {
  await Get.putAsync(() => MyServices().init());
}
