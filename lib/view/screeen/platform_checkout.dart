import 'package:ecommerce_app/controller/platform_checkout_controller.dart';
import 'package:ecommerce_app/core/class/statusrequest.dart';
import 'package:ecommerce_app/core/constant/color.dart';
import 'package:ecommerce_app/core/functions/storefront_display.dart';
import 'package:ecommerce_app/data/model/storefront_order_model.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';

class PlatformCheckout extends StatelessWidget {
  const PlatformCheckout({super.key});

  @override
  Widget build(BuildContext context) {
    Get.put(PlatformCheckoutController());

    return Scaffold(
      appBar: AppBar(
        title: Text(
          _label(
            ar: 'الدفع الآمن',
            en: 'Secure Checkout',
          ),
        ),
      ),
      body: GetBuilder<PlatformCheckoutController>(
        builder: (controller) {
          final quote = controller.quote;

          if (quote == null) {
            if (controller.statusRequest == StatusRequest.failure ||
                controller.statusRequest == StatusRequest.serverfailuer ||
                controller.statusRequest == StatusRequest.offlinefailuer) {
              return _FailureState(
                onRetry: controller.loadQuote,
              );
            }

            return const Center(
              child: CircularProgressIndicator(),
            );
          }

          final isArabic = Get.locale?.languageCode == 'ar';

          return ListView(
            padding: const EdgeInsets.all(20),
            children: [
              _Card(
                title: _label(
                  ar: 'ملخص الطلب',
                  en: 'Order Summary',
                ),
                child: Column(
                  children: [
                    _MoneyRow(
                      label: _label(
                        ar: 'قبل الضريبة',
                        en: 'Subtotal',
                      ),
                      value: formatStoreMinorMoney(
                        quote.subtotalMinor,
                        quote.currencyCode,
                        isArabic: isArabic,
                      ),
                    ),
                    _MoneyRow(
                      label: _label(
                        ar: 'الخصم',
                        en: 'Discount',
                      ),
                      value: formatStoreMinorMoney(
                        quote.discountMinor,
                        quote.currencyCode,
                        isArabic: isArabic,
                      ),
                    ),
                    _MoneyRow(
                      label: _label(
                        ar: 'الضريبة',
                        en: 'VAT',
                      ),
                      value: formatStoreMinorMoney(
                        quote.taxMinor,
                        quote.currencyCode,
                        isArabic: isArabic,
                      ),
                    ),
                    _MoneyRow(
                      label: _label(
                        ar: 'الشحن',
                        en: 'Shipping',
                      ),
                      value: formatStoreMinorMoney(
                        quote.shippingMinor,
                        quote.currencyCode,
                        isArabic: isArabic,
                      ),
                    ),
                    const Divider(height: 28),
                    _MoneyRow(
                      label: _label(
                        ar: 'الإجمالي',
                        en: 'Total',
                      ),
                      value: formatStoreMinorMoney(
                        quote.totalMinor,
                        quote.currencyCode,
                        isArabic: isArabic,
                      ),
                      emphasize: true,
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 16),
              _Card(
                title: _label(
                  ar: 'طريقة الدفع التجريبية',
                  en: 'Sandbox Payment Method',
                ),
                child: Column(
                  children: [
                    RadioListTile<String>(
                      value: 'card',
                      groupValue: controller.methodCode,
                      onChanged: controller.isBusy
                          ? null
                          : (value) {
                              if (value != null) {
                                controller.chooseMethod(value);
                              }
                            },
                      title: Text(
                        _label(
                          ar: 'بطاقة اختبار',
                          en: 'Test Card',
                        ),
                      ),
                      subtitle: const Text(
                        '4242 4242 4242 4242',
                      ),
                    ),
                    RadioListTile<String>(
                      value: 'mada',
                      groupValue: controller.methodCode,
                      onChanged: controller.isBusy
                          ? null
                          : (value) {
                              if (value != null) {
                                controller.chooseMethod(value);
                              }
                            },
                      title: Text(
                        _label(
                          ar: 'مدى تجريبي',
                          en: 'Sandbox Mada',
                        ),
                      ),
                      subtitle: Text(
                        _label(
                          ar: 'لا يتم إرسال رقم بطاقة حقيقي.',
                          en: 'No real card number is transmitted.',
                        ),
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 16),
              _Card(
                title: _label(
                  ar: 'سيناريو البطاقة الوهمية',
                  en: 'Fake Card Scenario',
                ),
                child: Column(
                  children: [
                    RadioListTile<String>(
                      value: 'success',
                      groupValue: controller.sandboxScenario,
                      onChanged:
                          controller.isBusy || controller.purchaseCompleted
                              ? null
                              : (value) {
                                  if (value != null) {
                                    controller.chooseScenario(value);
                                  }
                                },
                      title: Text(
                        _label(
                          ar: 'بطاقة ناجحة',
                          en: 'Successful test card',
                        ),
                      ),
                      subtitle: const Text(
                        '4242 4242 4242 4242',
                      ),
                    ),
                    RadioListTile<String>(
                      value: 'decline',
                      groupValue: controller.sandboxScenario,
                      onChanged:
                          controller.isBusy || controller.purchaseCompleted
                              ? null
                              : (value) {
                                  if (value != null) {
                                    controller.chooseScenario(value);
                                  }
                                },
                      title: Text(
                        _label(
                          ar: 'بطاقة مرفوضة',
                          en: 'Declined test card',
                        ),
                      ),
                      subtitle: const Text(
                        '4000 0000 0000 0002',
                      ),
                    ),
                    RadioListTile<String>(
                      value: 'cancel',
                      groupValue: controller.sandboxScenario,
                      onChanged:
                          controller.isBusy || controller.purchaseCompleted
                              ? null
                              : (value) {
                                  if (value != null) {
                                    controller.chooseScenario(value);
                                  }
                                },
                      title: Text(
                        _label(
                          ar: 'إلغاء العملية',
                          en: 'Cancelled payment',
                        ),
                      ),
                      subtitle: Text(
                        _label(
                          ar: 'محاكاة إلغاء العميل قبل اكتمال الدفع.',
                          en: 'Simulate a customer cancellation before settlement.',
                        ),
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 16),
              if (controller.order != null)
                _Card(
                  title: _label(
                    ar: 'الطلب',
                    en: 'Order',
                  ),
                  child: Text(
                    '${controller.order!.id}\n'
                    '${controller.order!.status}',
                  ),
                ),
              if (controller.paymentAttempt != null) ...[
                const SizedBox(height: 16),
                _Card(
                  title: _label(
                    ar: 'محاولة الدفع',
                    en: 'Payment Attempt',
                  ),
                  child: Text(
                    '${controller.paymentAttempt!.attemptId}\n'
                    '${controller.paymentAttempt!.attemptStatus}',
                  ),
                ),
              ],
              if (controller.orderDetails != null) ...[
                const SizedBox(height: 16),
                _VerifiedOrderCard(
                  order: controller.orderDetails!,
                ),
              ],
              const SizedBox(height: 24),
              ElevatedButton.icon(
                onPressed: controller.isBusy ||
                        !controller.sandboxAvailable ||
                        controller.purchaseCompleted
                    ? null
                    : controller.submitSandboxPurchase,
                icon: controller.isBusy
                    ? const SizedBox(
                        height: 18,
                        width: 18,
                        child: CircularProgressIndicator(
                          strokeWidth: 2,
                        ),
                      )
                    : const Icon(Icons.lock_outline),
                label: Text(
                  controller.purchaseCompleted
                      ? _label(
                          ar: 'اكتملت عملية الشراء التجريبية',
                          en: 'Sandbox Purchase Completed',
                        )
                      : _label(
                          ar: 'تنفيذ عملية شراء تجريبية',
                          en: 'Run Sandbox Purchase',
                        ),
                ),
                style: ElevatedButton.styleFrom(
                  backgroundColor: AppColor.secondColor,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(
                    vertical: 15,
                  ),
                ),
              ),
              const SizedBox(height: 10),
              Text(
                controller.sandboxAvailable
                    ? _label(
                        ar: 'بيئة اختبار فقط. لا يتم خصم أي مبلغ حقيقي.',
                        en: 'Test environment only. No real money is charged.',
                      )
                    : _label(
                        ar: 'Sandbox معطل خارج بيئة التطوير.',
                        en: 'Sandbox is disabled outside development.',
                      ),
                textAlign: TextAlign.center,
                style: TextStyle(
                  color: Colors.grey[600],
                  fontSize: 12,
                ),
              ),
            ],
          );
        },
      ),
    );
  }

  static String _label({
    required String ar,
    required String en,
  }) {
    return Get.locale?.languageCode == 'ar' ? ar : en;
  }
}

class _Card extends StatelessWidget {
  const _Card({
    required this.title,
    required this.child,
  });

  final String title;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(18),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.06),
            blurRadius: 12,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            title,
            style: const TextStyle(
              fontSize: 18,
              fontWeight: FontWeight.bold,
              color: AppColor.secondColor,
            ),
          ),
          const SizedBox(height: 12),
          child,
        ],
      ),
    );
  }
}

class _MoneyRow extends StatelessWidget {
  const _MoneyRow({
    required this.label,
    required this.value,
    this.emphasize = false,
  });

  final String label;
  final String value;
  final bool emphasize;

  @override
  Widget build(BuildContext context) {
    final style = TextStyle(
      fontSize: emphasize ? 18 : 15,
      fontWeight: emphasize ? FontWeight.bold : FontWeight.normal,
      color: emphasize ? AppColor.primaryColor : Colors.black87,
    );

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 5),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label, style: style),
          Text(value, style: style),
        ],
      ),
    );
  }
}

class _VerifiedOrderCard extends StatelessWidget {
  const _VerifiedOrderCard({
    required this.order,
  });

  final StorefrontOrderDetails order;

  @override
  Widget build(BuildContext context) {
    final isArabic = Get.locale?.languageCode == 'ar';

    return _Card(
      title: PlatformCheckout._label(
        ar: 'طلب موثّق من الخادم',
        en: 'Server-Verified Order',
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            order.id,
            style: const TextStyle(
              fontSize: 12,
              color: Colors.black54,
            ),
          ),
          const SizedBox(height: 10),
          _MoneyRow(
            label: PlatformCheckout._label(
              ar: 'حالة الطلب',
              en: 'Order Status',
            ),
            value: order.status,
            emphasize: true,
          ),
          if (order.payment != null)
            _MoneyRow(
              label: PlatformCheckout._label(
                ar: 'حالة الدفع',
                en: 'Payment Status',
              ),
              value: order.payment!.status,
            ),
          const Divider(height: 24),
          ...order.items.map(
            (item) => Padding(
              padding: const EdgeInsets.only(
                bottom: 12,
              ),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: Text(
                      isArabic ? item.productNameAr : item.productNameEn,
                      style: const TextStyle(
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Text(
                    '×${item.quantity} · '
                    '${formatStoreMinorMoney(
                      item.lineTotalMinor,
                      order.currencyCode,
                      isArabic: isArabic,
                    )}',
                    textAlign: TextAlign.end,
                  ),
                ],
              ),
            ),
          ),
          const Divider(height: 24),
          _MoneyRow(
            label: PlatformCheckout._label(
              ar: 'الإجمالي المؤكد',
              en: 'Confirmed Total',
            ),
            value: formatStoreMinorMoney(
              order.totalMinor,
              order.currencyCode,
              isArabic: isArabic,
            ),
            emphasize: true,
          ),
        ],
      ),
    );
  }
}

class _FailureState extends StatelessWidget {
  const _FailureState({
    required this.onRetry,
  });

  final Future<void> Function() onRetry;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(
              Icons.error_outline,
              size: 48,
              color: Colors.redAccent,
            ),
            const SizedBox(height: 12),
            Text(
              PlatformCheckout._label(
                ar: 'تعذر تحميل الدفع الآمن.',
                en: 'Unable to load secure checkout.',
              ),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 12),
            OutlinedButton(
              onPressed: onRetry,
              child: Text(
                PlatformCheckout._label(
                  ar: 'إعادة المحاولة',
                  en: 'Retry',
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
