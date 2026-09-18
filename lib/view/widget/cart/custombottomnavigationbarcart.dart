import 'package:ecommerce_app/core/constant/color.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';

import 'buttonorder.dart';

class BottomNavigationBarCart extends StatelessWidget {
  final String subtotal;
  final String discount;
  final String tax;
  final String shipping;
  final String total;
  final VoidCallback? onCheckout;

  const BottomNavigationBarCart({
    super.key,
    required this.subtotal,
    required this.discount,
    required this.tax,
    required this.shipping,
    required this.total,
    required this.onCheckout,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: const BorderRadius.only(
          topLeft: Radius.circular(25),
          topRight: Radius.circular(25),
        ),
        boxShadow: [
          BoxShadow(
            color: Colors.grey.withValues(alpha: 0.3),
            spreadRadius: 1,
            blurRadius: 10,
            offset: const Offset(0, -3),
          ),
        ],
      ),
      padding: const EdgeInsets.only(
        top: 12,
        bottom: 18,
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            padding: const EdgeInsets.all(15),
            margin: const EdgeInsets.fromLTRB(
              15,
              0,
              15,
              12,
            ),
            decoration: BoxDecoration(
              color: Colors.white,
              border: Border.all(
                color: AppColor.primaryColor.withValues(alpha: 0.3),
              ),
              borderRadius: BorderRadius.circular(15),
              boxShadow: [
                BoxShadow(
                  color: Colors.grey.withValues(alpha: 0.1),
                  spreadRadius: 1,
                  blurRadius: 5,
                  offset: const Offset(0, 2),
                ),
              ],
            ),
            child: Column(
              children: [
                _buildPriceRow(
                  '86'.tr,
                  subtotal,
                  false,
                ),
                const SizedBox(height: 6),
                _buildPriceRow(
                  _label(
                    ar: 'الخصم',
                    en: 'Discount',
                  ),
                  discount,
                  false,
                ),
                const SizedBox(height: 6),
                _buildPriceRow(
                  _label(
                    ar: 'الضريبة',
                    en: 'VAT',
                  ),
                  tax,
                  false,
                ),
                const SizedBox(height: 6),
                _buildPriceRow(
                  '88'.tr,
                  shipping,
                  false,
                ),
                Padding(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 10,
                    vertical: 10,
                  ),
                  child: Divider(
                    color: Colors.grey.shade300,
                    thickness: 1,
                  ),
                ),
                _buildPriceRow(
                  '89'.tr,
                  total,
                  true,
                ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.symmetric(
              horizontal: 15,
            ),
            child: Opacity(
              opacity: onCheckout == null ? 0.55 : 1,
              child: CoustombuttonCart(
                textButton: '90'.tr,
                onPressed: onCheckout,
              ),
            ),
          ),
          if (onCheckout == null)
            Padding(
              padding: const EdgeInsets.fromLTRB(
                20,
                8,
                20,
                0,
              ),
              child: Text(
                _label(
                  ar: 'يتم الآن ربط الدفع الآمن بالمنصة الجديدة.',
                  en: 'Secure checkout is being connected to the new platform.',
                ),
                textAlign: TextAlign.center,
                style: TextStyle(
                  fontSize: 12,
                  color: Colors.grey[600],
                ),
              ),
            ),
        ],
      ),
    );
  }

  Widget _buildPriceRow(
    String title,
    String value,
    bool isTotal,
  ) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Text(
          title,
          style: TextStyle(
            fontSize: isTotal ? 18 : 16,
            fontWeight: isTotal ? FontWeight.bold : FontWeight.normal,
            color: isTotal ? AppColor.primaryColor : Colors.black87,
          ),
        ),
        Text(
          value,
          style: TextStyle(
            fontSize: isTotal ? 18 : 16,
            fontWeight: isTotal ? FontWeight.bold : FontWeight.normal,
            color: isTotal ? AppColor.primaryColor : Colors.black87,
          ),
        ),
      ],
    );
  }

  String _label({
    required String ar,
    required String en,
  }) {
    return Get.locale?.languageCode == 'ar' ? ar : en;
  }
}
