import 'package:ecommerce_app/core/class/statusrequest.dart';
import 'package:ecommerce_app/core/constant/routes.dart';
import 'package:ecommerce_app/core/network/api_exception.dart';
import 'package:ecommerce_app/core/network/status_request_mapper.dart';
import 'package:ecommerce_app/core/services/notification_service.dart';
import 'package:ecommerce_app/data/datasource/remote/storefront_cart_data.dart';
import 'package:ecommerce_app/data/model/storefront_cart_model.dart';
import 'package:get/get.dart';

class CartController extends GetxController {
  CartController({
    StorefrontCartData? cartData,
  }) : cartData = cartData ?? StorefrontCartData();

  final StorefrontCartData cartData;

  NotificationService get notificationService =>
      Get.find<NotificationService>();

  StatusRequest statusRequest = StatusRequest.none;

  StorefrontCartSnapshot? snapshot;

  List<StorefrontCartItem> get data =>
      snapshot?.items ?? const <StorefrontCartItem>[];

  int get totalcountitems => snapshot?.totalQuantity ?? 0;

  String get currencyCode => snapshot?.currencyCode ?? 'SAR';

  int get subtotalMinor => snapshot?.totals.subtotalMinor ?? 0;

  int get discountMinor => snapshot?.totals.discountMinor ?? 0;

  int get taxMinor => snapshot?.totals.taxMinor ?? 0;

  int get shippingMinor => snapshot?.totals.shippingMinor ?? 0;

  int get totalMinor => snapshot?.totals.totalMinor ?? 0;

  Future<void> view() async {
    statusRequest = StatusRequest.loading;
    update();

    try {
      _applySnapshot(
        await cartData.load(),
      );
      statusRequest = StatusRequest.success;
    } catch (error) {
      statusRequest = statusRequestForError(error);
    }

    update();
  }

  Future<void> increment(
    StorefrontCartItem item,
  ) async {
    if (item.quantity >= item.maxQuantity) {
      _showCartError(
        _localized(
          ar: 'الكمية المتاحة في المخزون لا تسمح بالمزيد.',
          en: 'No more stock is currently available.',
        ),
      );
      return;
    }

    await _mutate(
      () => cartData.incrementSku(
        item.skuId,
      ),
      successMessage: _localized(
        ar: 'تم تحديث السلة.',
        en: 'Cart updated.',
      ),
    );
  }

  Future<void> decrement(
    StorefrontCartItem item,
  ) async {
    await _mutate(
      () => cartData.decrementSku(
        item.skuId,
      ),
      successMessage: _localized(
        ar: 'تم تحديث السلة.',
        en: 'Cart updated.',
      ),
    );
  }

  Future<void> remove(
    StorefrontCartItem item,
  ) async {
    await _mutate(
      () => cartData.removeItem(
        cartItemId: item.cartItemId,
      ),
      successMessage: _localized(
        ar: 'تم حذف المنتج من السلة.',
        en: 'Item removed from cart.',
      ),
    );
  }

  Future<void> _mutate(
    Future<StorefrontCartSnapshot> Function() action, {
    required String successMessage,
  }) async {
    statusRequest = StatusRequest.loading;
    update();

    try {
      _applySnapshot(
        await action(),
      );
      statusRequest = StatusRequest.success;

      notificationService.showSuccessNotification(
        title: '71'.tr,
        message: successMessage,
      );
    } catch (error) {
      statusRequest = statusRequestForError(error);

      _showCartError(
        _messageForError(error),
      );
    }

    update();
  }

  void _applySnapshot(
    StorefrontCartSnapshot value,
  ) {
    snapshot = value;
  }

  String _messageForError(Object error) {
    if (error is ApiNetworkException) {
      return _localized(
        ar: 'تعذر الاتصال بالخدمة. تحقق من اتصال الإنترنت.',
        en: 'Unable to reach the service. Check your internet connection.',
      );
    }

    if (error is ApiException) {
      final code = _apiErrorCode(error);

      if (code == 'INSUFFICIENT_STOCK') {
        return _localized(
          ar: 'الكمية المطلوبة غير متاحة حاليًا.',
          en: 'The requested quantity is not currently available.',
        );
      }

      if (code == 'CART_REVIEW_REQUIRED') {
        return _localized(
          ar: 'تغيرت بيانات السلة وتحتاج إلى مراجعة قبل المتابعة.',
          en: 'The cart changed and must be reviewed before continuing.',
        );
      }

      if (code == 'CART_NOT_MUTABLE') {
        return _localized(
          ar: 'لا يمكن تعديل هذه السلة الآن.',
          en: 'This cart can no longer be modified.',
        );
      }
    }

    return _localized(
      ar: 'تعذر تحديث السلة. حاول مرة أخرى.',
      en: 'Unable to update the cart. Please try again.',
    );
  }

  String? _apiErrorCode(ApiException error) {
    final raw = error.data;

    if (raw is! Map) {
      return null;
    }

    final errorPayload = raw['error'];

    if (errorPayload is! Map) {
      return null;
    }

    return errorPayload['code']?.toString();
  }

  void _showCartError(String message) {
    notificationService.showErrorNotification(
      title: '71'.tr,
      message: message,
    );
  }

  String _localized({
    required String ar,
    required String en,
  }) {
    return Get.locale?.languageCode == 'ar' ? ar : en;
  }

  void goToPageCheckout() {
    if (data.isEmpty) {
      _showCartError(
        '91'.tr,
      );
      return;
    }

    Get.toNamed(
      AppRoute.platformCheckout,
    );
  }

  @override
  void onInit() {
    view();
    super.onInit();
  }
}
