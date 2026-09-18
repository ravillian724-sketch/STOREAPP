import 'package:ecommerce_app/controller/cart_controller.dart';
import 'package:ecommerce_app/core/class/handlingdataview.dart';
import 'package:ecommerce_app/core/class/statusrequest.dart';
import 'package:ecommerce_app/core/functions/storefront_display.dart';
import 'package:ecommerce_app/core/functions/translatedatabase.dart';
import 'package:ecommerce_app/view/widget/cart/custombottomnavigationbarcart.dart';
import 'package:ecommerce_app/view/widget/cart/customitemscartlist.dart';
import 'package:ecommerce_app/view/widget/cart/topcardcart.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';

class Cart extends StatelessWidget {
  const Cart({
    super.key,
  });

  @override
  Widget build(BuildContext context) {
    Get.put(CartController());

    return Scaffold(
      appBar: AppBar(
        title: Text(
          '78'.tr,
        ),
      ),
      backgroundColor: Colors.grey[50],
      bottomNavigationBar: GetBuilder<CartController>(
        builder: (controller) {
          final isArabic = Get.locale?.languageCode == 'ar';

          return BottomNavigationBarCart(
            subtotal: formatStoreMinorMoney(
              controller.subtotalMinor,
              controller.currencyCode,
              isArabic: isArabic,
            ),
            discount: formatStoreMinorMoney(
              controller.discountMinor,
              controller.currencyCode,
              isArabic: isArabic,
            ),
            tax: formatStoreMinorMoney(
              controller.taxMinor,
              controller.currencyCode,
              isArabic: isArabic,
            ),
            shipping: formatStoreMinorMoney(
              controller.shippingMinor,
              controller.currencyCode,
              isArabic: isArabic,
            ),
            total: formatStoreMinorMoney(
              controller.totalMinor,
              controller.currencyCode,
              isArabic: isArabic,
            ),
            onCheckout: null,
          );
        },
      ),
      body: GetBuilder<CartController>(
        builder: (controller) => HandlingDataView(
          statusRequest: controller.statusRequest,
          widget: controller.data.isEmpty
              ? _EmptyCart()
              : _CartItems(
                  controller: controller,
                ),
        ),
      ),
    );
  }
}

class _EmptyCart extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: [
            Colors.blue.shade50,
            Colors.white,
          ],
          stops: const [0, 0.4],
        ),
      ),
      child: Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: Colors.white,
                shape: BoxShape.circle,
                boxShadow: [
                  BoxShadow(
                    color: Colors.blue.withValues(
                      alpha: 0.1,
                    ),
                    blurRadius: 20,
                    spreadRadius: 10,
                  ),
                ],
              ),
              child: Image.asset(
                'assets/images/empty_cart.png',
                height: 150,
                width: 150,
              ),
            ),
            const SizedBox(height: 5),
            Text(
              '79'.tr,
              style: TextStyle(
                fontSize: 28,
                fontWeight: FontWeight.bold,
                color: Colors.grey[800],
                letterSpacing: 1,
              ),
            ),
            const SizedBox(height: 15),
            Text(
              '80'.tr,
              textAlign: TextAlign.center,
              style: TextStyle(
                fontSize: 16,
                color: Colors.grey[600],
                height: 1.5,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _CartItems extends StatelessWidget {
  const _CartItems({
    required this.controller,
  });

  final CartController controller;

  @override
  Widget build(BuildContext context) {
    final isArabic = Get.locale?.languageCode == 'ar';
    final isMutating = controller.statusRequest == StatusRequest.loading;

    return Container(
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: [
            Colors.blue.shade50,
            Colors.white,
          ],
          stops: const [0, 0.3],
        ),
      ),
      child: ListView(
        physics: const BouncingScrollPhysics(),
        children: [
          const SizedBox(height: 15),
          TopCardCart(
            message: '${'81'.tr}'
                '${controller.totalcountitems}'
                '${'82'.tr}',
          ),
          Container(
            padding: const EdgeInsets.all(15),
            child: Column(
              children: [
                ...controller.data.asMap().entries.map(
                  (entry) {
                    final index = entry.key;
                    final item = entry.value;

                    return Container(
                      margin: const EdgeInsets.only(
                        bottom: 15,
                      ),
                      child: TweenAnimationBuilder<double>(
                        duration: Duration(
                          milliseconds: 400 + (index * 100),
                        ),
                        tween: Tween<double>(
                          begin: 0,
                          end: 1,
                        ),
                        builder: (
                          context,
                          value,
                          child,
                        ) {
                          return Transform.scale(
                            scale: value,
                            child: CustomItemsCartList(
                              name: translateDatabase(
                                item.nameAr,
                                item.nameEn,
                              ),
                              lineTotal: formatStoreMinorMoney(
                                item.pricing.lineTotalMinor,
                                item.pricing.currencyCode,
                                isArabic: isArabic,
                              ),
                              count: item.quantity.toString(),
                              imageUrl: item.imageUrl,
                              onAdd: isMutating ||
                                      item.quantity >= item.maxQuantity
                                  ? null
                                  : () async {
                                      await controller.increment(
                                        item,
                                      );
                                    },
                              onRemove: isMutating
                                  ? null
                                  : () async {
                                      await controller.decrement(
                                        item,
                                      );
                                    },
                            ),
                          );
                        },
                      ),
                    );
                  },
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
