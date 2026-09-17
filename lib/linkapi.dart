import 'package:ecommerce_app/core/platform/environment_config.dart';

/// Transitional endpoints for legacy services that have not yet moved to API v1.
/// No IP/domain fallback is allowed: builds must provide API_BASE_URL explicitly.
class AppLink {
  static String get server => EnvironmentConfig.requireApiBaseUri().toString();

  static String get imageStatic => '$server/upload';
  static String get imageCategories => '$imageStatic/categories';
  static String get imageItems => '$imageStatic/items';
  static String get imagesubcategories => '$imageStatic/subcategories';

  static String get signUp => '$server/auth/signup.php';
  static String get verfiycodesignup => '$server/auth/verfiycode.php';
  static String get resend => '$server/auth/resend.php';
  static String get login => '$server/auth/login.php';

  static String get checkemail => '$server/forgetpassword/checkemail.php';
  static String get verfiycodeforgetpassword =>
      '$server/forgetpassword/verfiycode.php';
  static String get resetpassword => '$server/forgetpassword/resetpassword.php';

  static String get homepage => '$server/home.php';
  static String get subcategories => '$server/subcategories/view.php';
  static String get items => '$server/items/items.php';
  static String get searchitems => '$server/items/search.php';

  static String get favoriteAdd => '$server/favorite/add.php';
  static String get favoriteRemove => '$server/favorite/remove.php';
  static String get favoriteview => '$server/favorite/view.php';
  static String get deletefromfavorite =>
      '$server/favorite/deletefromfavorite.php';

  static String get cartview => '$server/cart/view.php';
  static String get cartAdd => '$server/cart/add.php';
  static String get cartDelete => '$server/cart/delete.php';
  static String get cartgetcountitems => '$server/cart/getcountitems.php';

  static String get addressView => '$server/address/view.php';
  static String get addressAdd => '$server/address/add.php';
  static String get addressEdit => '$server/address/edit.php';
  static String get addressDelete => '$server/address/delete.php';

  static String get checkCoupon => '$server/coupon/checkcoupon.php';
  static String get checkout => '$server/orders/checkout.php';
  static String get pendingorders => '$server/orders/pending.php';
  static String get ordersdetails => '$server/orders/details.php';
  static String get ordersdelete => '$server/orders/delete.php';
  static String get ordersarchive => '$server/orders/archive.php';
  static String get rejectedorders => '$server/orders/rejected.php';
  static String get notification => '$server/notification.php';
  static String get offers => '$server/offers.php';
  static String get chatbot => '$server/chatbot.php';
  static String get geminisave => '$server/save_ai_caht.php';
  static String get rating => '$server/rating.php';
  static String get sendmessage => '$server/chat/send.php';
  static String get getmessage => '$server/chat/get.php';
  static String get medicalInfoView => '$server/mediacl_info/view.php';
  static String get medicalInfoAdd => '$server/mediacl_info/add.php';
  static String get medicalInfoUpdate => '$server/mediacl_info/edit.php';
  static String get usersview => '$server/admin/admin_users/view.php';
}
