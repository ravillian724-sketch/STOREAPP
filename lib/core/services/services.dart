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
    return sharedPreferences.getString("step") == "2" && userId != null;
  }

  bool get isPlatformCustomerSession {
    return hasValidSession &&
        sharedPreferences.getString("auth_provider") == "platform";
  }

  Future<void> savePlatformCustomerProfile({
    required String id,
    required String name,
    required String email,
    String? phone,
  }) async {
    await sharedPreferences.setString("id", id.trim());
    await sharedPreferences.setString("username", name.trim());
    await sharedPreferences.setString("email", email.trim());

    final normalizedPhone = phone?.trim();
    if (normalizedPhone == null || normalizedPhone.isEmpty) {
      await sharedPreferences.remove("phone");
    } else {
      await sharedPreferences.setString("phone", normalizedPhone);
    }

    await sharedPreferences.setString("auth_provider", "platform");
    await sharedPreferences.setString("step", "2");
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
