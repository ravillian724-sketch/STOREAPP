import 'package:ecommerce_app/core/constant/routes.dart';
import 'package:ecommerce_app/core/services/services.dart';
import 'package:get/get.dart';

/// Returns the authenticated MySQL user id.
///
/// If the local session is missing/corrupted, it clears the stale session
/// and safely redirects the user to the login page instead of crashing.
Future<String?> requireUserId(MyServices services) async {
  final String? userId = services.userId;

  if (userId != null) {
    return userId;
  }

  await services.clearUserSession();

  if (Get.currentRoute != AppRoute.login) {
    Get.offAllNamed(AppRoute.login);
  }

  return null;
}
