import 'package:ecommerce_app/core/class/statusrequest.dart';
import 'package:ecommerce_app/core/constant/routes.dart';
import 'package:ecommerce_app/core/functions/handlingdatacontroller.dart';
import 'package:ecommerce_app/core/services/services.dart';
import 'package:ecommerce_app/data/datasource/remote/auth/google_login.dart';
import 'package:ecommerce_app/data/datasource/remote/auth/login.dart';
import 'package:firebase_auth/firebase_auth.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/cupertino.dart';
import 'package:get/get.dart';
import 'package:google_sign_in/google_sign_in.dart';

abstract class LoginController extends GetxController {
  login();
  loginWithGoogle();
  goToSignUp();
  goToForgetPassword();
}

class LoginControllerImp extends LoginController {
  final LoginData loginData = LoginData(Get.find());
  final GoogleLoginData googleLoginData = GoogleLoginData(Get.find());

  final GlobalKey<FormState> formstate = GlobalKey<FormState>();

  late TextEditingController email;
  late TextEditingController password;

  bool isShowPassword = true;

  final MyServices myServices = Get.find();

  StatusRequest statusRequest = StatusRequest.none;

  void showPassword() {
    isShowPassword = !isShowPassword;
    update();
  }

  Future<void> _saveSession(dynamic user) async {
    await myServices.sharedPreferences.setString(
      "id",
      user["users_id"].toString(),
    );

    await myServices.sharedPreferences.setString(
      "username",
      user["users_name"]?.toString() ?? "",
    );

    await myServices.sharedPreferences.setString(
      "email",
      user["users_email"]?.toString() ?? "",
    );

    await myServices.sharedPreferences.setString(
      "phone",
      user["users_phone"]?.toString() ?? "",
    );

    await myServices.sharedPreferences.setString("step", "2");

    final String userId = user["users_id"].toString();

    await FirebaseMessaging.instance.subscribeToTopic("users");
    await FirebaseMessaging.instance.subscribeToTopic("users$userId");
  }

  @override
  Future<void> login() async {
    final formData = formstate.currentState;

    if (formData == null || !formData.validate()) {
      return;
    }

    statusRequest = StatusRequest.loading;
    update();

    final response = await loginData.postData(
      email.text.trim(),
      password.text,
    );

    statusRequest = handlingData(response);

    if (statusRequest == StatusRequest.success) {
      if (response["status"] == "success") {
        final user = response["data"];

        if (user["users_approve"] == 1 ||
            user["users_approve"].toString() == "1") {
          await _saveSession(user);
          Get.offAllNamed(AppRoute.homepage);
        } else {
          Get.toNamed(
            AppRoute.verfiyCodeSignUp,
            arguments: {"email": email.text.trim()},
          );
        }
      } else {
        statusRequest = StatusRequest.failure;
        Get.defaultDialog(
          title: "44".tr,
          middleText: "47".tr,
        );
      }
    }

    update();
  }

  @override
  Future<void> loginWithGoogle() async {
    try {
      statusRequest = StatusRequest.loading;
      update();

      final GoogleSignIn googleSignIn = GoogleSignIn(
        scopes: <String>[
          "email",
          "profile",
        ],
      );

      final GoogleSignInAccount? googleUser = await googleSignIn.signIn();

      // User closed the Google account chooser.
      if (googleUser == null) {
        statusRequest = StatusRequest.none;
        update();
        return;
      }

      final GoogleSignInAuthentication googleAuth =
          await googleUser.authentication;

      final OAuthCredential credential = GoogleAuthProvider.credential(
        accessToken: googleAuth.accessToken,
        idToken: googleAuth.idToken,
      );

      final UserCredential firebaseCredential =
          await FirebaseAuth.instance.signInWithCredential(credential);

      final User? firebaseUser = firebaseCredential.user;

      if (firebaseUser == null) {
        throw Exception("Firebase user was not returned.");
      }

      final String? firebaseIdToken = await firebaseUser.getIdToken(true);

      if (firebaseIdToken == null || firebaseIdToken.isEmpty) {
        throw Exception("Firebase ID token was not returned.");
      }

      // Backend must verify the Firebase token and return the real MySQL users_id.
      final response =
          await googleLoginData.postData(firebaseIdToken);

      statusRequest = handlingData(response);

      if (statusRequest == StatusRequest.success &&
          response is Map &&
          response["status"] == "success" &&
          response["data"] != null) {
        await _saveSession(response["data"]);

        await myServices.sharedPreferences.setString(
          "auth_provider",
          "google",
        );

        Get.offAllNamed(AppRoute.homepage);
        update();
        return;
      }

      statusRequest = StatusRequest.failure;

      Get.defaultDialog(
        title: "Google Sign-In",
        middleText:
            "تم التحقق من حساب Google، لكن لم يتم ربط الحساب بخادم المتجر بعد.",
      );
    } on FirebaseAuthException catch (e) {
      statusRequest = StatusRequest.failure;

      Get.defaultDialog(
        title: "Google Sign-In",
        middleText: e.message ?? "Firebase authentication failed.",
      );
    } catch (e) {
      statusRequest = StatusRequest.failure;

      Get.defaultDialog(
        title: "Google Sign-In",
        middleText:
            "تعذر تسجيل الدخول باستخدام Google.\n\n$e",
      );
    }

    update();
  }

  @override
  void goToSignUp() {
    Get.offNamed(AppRoute.signUp);
  }

  @override
  void goToForgetPassword() {
    Get.offNamed(AppRoute.forgetPassword);
  }

  @override
  void onInit() {
    email = TextEditingController();
    password = TextEditingController();

    FirebaseMessaging.instance.getToken().then((value) {
      debugPrint("Firebase token: $value");
    });

    super.onInit();
  }

  @override
  void dispose() {
    email.dispose();
    password.dispose();
    super.dispose();
  }
}
