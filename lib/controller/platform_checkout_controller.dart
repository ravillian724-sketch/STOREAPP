import 'package:ecommerce_app/core/class/statusrequest.dart';
import 'package:ecommerce_app/core/network/api_exception.dart';
import 'package:ecommerce_app/core/network/status_request_mapper.dart';
import 'package:ecommerce_app/core/platform/environment_config.dart';
import 'package:ecommerce_app/core/services/notification_service.dart';
import 'package:ecommerce_app/data/datasource/remote/storefront_checkout_data.dart';
import 'package:ecommerce_app/data/datasource/remote/storefront_order_data.dart';
import 'package:ecommerce_app/data/model/storefront_checkout_model.dart';
import 'package:ecommerce_app/data/model/storefront_order_model.dart';
import 'package:get/get.dart';

class PlatformCheckoutController extends GetxController {
  PlatformCheckoutController({
    StorefrontCheckoutData? checkoutData,
    StorefrontOrderData? orderData,
  })  : checkoutData = checkoutData ?? StorefrontCheckoutData(),
        orderData = orderData ?? StorefrontOrderData();

  final StorefrontCheckoutData checkoutData;
  final StorefrontOrderData orderData;

  NotificationService get notificationService =>
      Get.find<NotificationService>();

  StatusRequest statusRequest = StatusRequest.none;

  StorefrontCheckoutQuote? quote;
  StorefrontCheckoutOrder? order;
  StorefrontOrderDetails? orderDetails;
  StorefrontPaymentAttemptResult? paymentAttempt;
  StorefrontSandboxSettlement? settlement;

  String methodCode = 'card';
  String sandboxScenario = 'success';

  bool get sandboxAvailable =>
      EnvironmentConfig.environment == AppEnvironment.development;

  bool get isBusy => statusRequest == StatusRequest.loading;

  bool get purchaseCompleted => settlement?.succeeded == true;

  void chooseMethod(String value) {
    methodCode = value;
    update();
  }

  void chooseScenario(String value) {
    sandboxScenario = value;
    update();
  }

  Future<void> loadQuote() async {
    statusRequest = StatusRequest.loading;
    update();

    try {
      quote = await checkoutData.quote();
      statusRequest = StatusRequest.success;
    } catch (error) {
      statusRequest = statusRequestForError(error);
      _showError(_messageForError(error));
    }

    update();
  }

  Future<void> submitSandboxPurchase() async {
    if (quote == null || isBusy) {
      return;
    }

    if (!sandboxAvailable) {
      _showError(
        _localized(
          ar: 'بوابة الدفع الإنتاجية لم تُضبط لهذا المتجر بعد.',
          en: 'A production payment provider is not configured for this store.',
        ),
      );
      return;
    }

    statusRequest = StatusRequest.loading;
    update();

    try {
      order ??= await checkoutData.createOrder(
        customerName: 'Sandbox Buyer',
      );

      paymentAttempt = await checkoutData.createPaymentAttempt(
        providerCode: 'sandbox',
        methodCode: methodCode,
      );

      settlement = await checkoutData.settleSandboxPayment(
        attemptId: paymentAttempt!.attemptId,
        scenario: sandboxScenario,
      );

      order = settlement!.order;
      paymentAttempt = settlement!.paymentAttempt;

      if (settlement!.succeeded) {
        try {
          orderDetails = await orderData.getOrder(
            order!.id,
          );
        } catch (_) {
          // Payment success is authoritative. A post-payment order refresh
          // failure must not downgrade a confirmed purchase.
        }
      }

      statusRequest = StatusRequest.success;

      notificationService.showSuccessNotification(
        title: _localized(
          ar: 'نتيجة الدفع التجريبي',
          en: 'Sandbox payment result',
        ),
        message: settlement!.succeeded
            ? _localized(
                ar: 'تمت عملية الشراء التجريبية بنجاح بدون خصم مبلغ حقيقي.',
                en: 'The sandbox purchase completed successfully with no real charge.',
              )
            : settlement!.declined
                ? _localized(
                    ar: 'تمت محاكاة رفض البطاقة بنجاح ويمكنك إعادة المحاولة.',
                    en: 'The card decline was simulated successfully. You can retry.',
                  )
                : _localized(
                    ar: 'تمت محاكاة إلغاء عملية الدفع.',
                    en: 'The sandbox payment cancellation was simulated.',
                  ),
      );
    } catch (error) {
      statusRequest = statusRequestForError(error);
      _showError(_messageForError(error));
    }

    update();
  }

  String _messageForError(Object error) {
    if (error is ApiNetworkException) {
      return _localized(
        ar: 'تعذر الاتصال بخدمة الدفع. تحقق من اتصال الإنترنت.',
        en: 'Unable to reach checkout. Check your internet connection.',
      );
    }

    if (error is ApiException) {
      final code = _apiErrorCode(error);

      if (code == 'INSUFFICIENT_STOCK') {
        return _localized(
          ar: 'تغير المخزون والكمية لم تعد متاحة.',
          en: 'Stock changed and the requested quantity is no longer available.',
        );
      }

      if (code == 'CHECKOUT_REVIEW_REQUIRED') {
        return _localized(
          ar: 'تغيرت بيانات السلة وتحتاج إلى مراجعة قبل الدفع.',
          en: 'The cart changed and must be reviewed before checkout.',
        );
      }

      if (code == 'PAYMENT_IDEMPOTENCY_CONFLICT') {
        return _localized(
          ar: 'تعارضت محاولة الدفع مع محاولة سابقة. أعد تحميل صفحة الدفع.',
          en: 'The payment attempt conflicts with an earlier request. Reload checkout.',
        );
      }

      if (code == 'PAYMENT_NOT_AVAILABLE') {
        return _localized(
          ar: 'الدفع غير متاح لهذا الطلب حاليًا.',
          en: 'Payment is not currently available for this order.',
        );
      }
    }

    return _localized(
      ar: 'تعذر إكمال عملية الدفع التجريبية. حاول مرة أخرى.',
      en: 'Unable to complete the sandbox checkout. Please try again.',
    );
  }

  String? _apiErrorCode(ApiException error) {
    final raw = error.data;

    if (raw is! Map) {
      return null;
    }

    final payload = raw['error'];

    if (payload is! Map) {
      return null;
    }

    return payload['code']?.toString();
  }

  void _showError(String message) {
    notificationService.showErrorNotification(
      title: _localized(
        ar: 'الدفع',
        en: 'Checkout',
      ),
      message: message,
    );
  }

  String _localized({
    required String ar,
    required String en,
  }) {
    return Get.locale?.languageCode == 'ar' ? ar : en;
  }

  @override
  void onInit() {
    loadQuote();
    super.onInit();
  }
}
