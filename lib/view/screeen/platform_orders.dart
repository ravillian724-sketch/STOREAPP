import 'package:ecommerce_app/controller/platform_orders_controller.dart';
import 'package:ecommerce_app/core/class/statusrequest.dart';
import 'package:ecommerce_app/core/constant/color.dart';
import 'package:ecommerce_app/core/functions/storefront_display.dart';
import 'package:ecommerce_app/data/model/storefront_order_model.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';

class PlatformOrders extends StatelessWidget {
  const PlatformOrders({super.key});

  static String _label({
    required String ar,
    required String en,
  }) {
    return Get.locale?.languageCode == 'ar' ? ar : en;
  }

  @override
  Widget build(BuildContext context) {
    Get.put(PlatformOrdersController());

    return Scaffold(
      appBar: AppBar(
        title: Text(
          _label(
            ar: 'طلباتي',
            en: 'My Orders',
          ),
        ),
      ),
      body: GetBuilder<PlatformOrdersController>(
        builder: (controller) {
          if (controller.statusRequest == StatusRequest.loading) {
            return const Center(
              child: CircularProgressIndicator(),
            );
          }

          if (controller.statusRequest != StatusRequest.success) {
            return _OrdersFailure(
              onRetry: controller.loadOrders,
            );
          }

          if (controller.orders.isEmpty) {
            return RefreshIndicator(
              onRefresh: controller.refreshOrders,
              child: ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                children: const [
                  SizedBox(height: 150),
                  _EmptyOrders(),
                ],
              ),
            );
          }

          return RefreshIndicator(
            onRefresh: controller.refreshOrders,
            child: ListView.separated(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(16),
              itemCount: controller.orders.length,
              separatorBuilder: (_, __) => const SizedBox(height: 12),
              itemBuilder: (context, index) {
                return _OrderCard(
                  order: controller.orders[index],
                );
              },
            ),
          );
        },
      ),
    );
  }
}

class _OrderCard extends StatelessWidget {
  const _OrderCard({
    required this.order,
  });

  final StorefrontOrderDetails order;

  @override
  Widget build(BuildContext context) {
    final isArabic = Get.locale?.languageCode == 'ar';
    final total = formatStoreMinorMoney(
      order.totalMinor,
      order.currencyCode,
      isArabic: isArabic,
    );

    return Card(
      clipBehavior: Clip.antiAlias,
      child: ExpansionTile(
        leading: CircleAvatar(
          backgroundColor: AppColor.primaryColor.withValues(alpha: 0.12),
          child: const Icon(
            Icons.receipt_long_outlined,
            color: AppColor.primaryColor,
          ),
        ),
        title: Text(
          '#${_shortId(order.id)}',
          style: const TextStyle(
            fontWeight: FontWeight.w700,
          ),
        ),
        subtitle: Padding(
          padding: const EdgeInsets.only(top: 6),
          child: Wrap(
            spacing: 8,
            runSpacing: 6,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              _StatusChip(
                label: _orderStatus(order.status),
                positive: order.status == 'confirmed',
              ),
              Text(
                total,
                style: const TextStyle(
                  fontWeight: FontWeight.w600,
                ),
              ),
              if (order.createdAt != null)
                Text(
                  _formatDate(order.createdAt!),
                  style: const TextStyle(
                    color: Colors.black54,
                    fontSize: 12,
                  ),
                ),
            ],
          ),
        ),
        childrenPadding: const EdgeInsets.fromLTRB(
          16,
          0,
          16,
          16,
        ),
        children: [
          const Divider(),
          if (order.payment != null)
            _DetailRow(
              label: PlatformOrders._label(
                ar: 'حالة الدفع',
                en: 'Payment',
              ),
              value: _paymentStatus(
                order.payment!.status,
              ),
            ),
          _DetailRow(
            label: PlatformOrders._label(
              ar: 'عدد المنتجات',
              en: 'Items',
            ),
            value: order.items.length.toString(),
          ),
          const SizedBox(height: 8),
          ...order.items.map(
            (item) => _OrderItemRow(
              item: item,
              currencyCode: order.currencyCode,
            ),
          ),
          const Divider(height: 24),
          _DetailRow(
            label: PlatformOrders._label(
              ar: 'الإجمالي',
              en: 'Total',
            ),
            value: total,
            emphasize: true,
          ),
          const SizedBox(height: 8),
          SelectableText(
            order.id,
            style: const TextStyle(
              color: Colors.black45,
              fontSize: 11,
            ),
          ),
        ],
      ),
    );
  }

  static String _shortId(String value) {
    return value.length <= 8 ? value : value.substring(0, 8);
  }

  static String _formatDate(DateTime value) {
    final local = value.toLocal();
    String two(int number) => number.toString().padLeft(2, '0');

    return '${local.year}-${two(local.month)}-${two(local.day)} '
        '${two(local.hour)}:${two(local.minute)}';
  }

  static String _orderStatus(String value) {
    return switch (value) {
      'pending' => PlatformOrders._label(
          ar: 'قيد الانتظار',
          en: 'Pending',
        ),
      'confirmed' => PlatformOrders._label(
          ar: 'مؤكد',
          en: 'Confirmed',
        ),
      'cancelled' => PlatformOrders._label(
          ar: 'ملغي',
          en: 'Cancelled',
        ),
      _ => value,
    };
  }

  static String _paymentStatus(String value) {
    return switch (value) {
      'pending' => PlatformOrders._label(
          ar: 'قيد الانتظار',
          en: 'Pending',
        ),
      'paid' => PlatformOrders._label(
          ar: 'مدفوع',
          en: 'Paid',
        ),
      'cancelled' => PlatformOrders._label(
          ar: 'ملغي',
          en: 'Cancelled',
        ),
      'failed' => PlatformOrders._label(
          ar: 'فشل',
          en: 'Failed',
        ),
      _ => value,
    };
  }
}

class _OrderItemRow extends StatelessWidget {
  const _OrderItemRow({
    required this.item,
    required this.currencyCode,
  });

  final StorefrontOrderItem item;
  final String currencyCode;

  @override
  Widget build(BuildContext context) {
    final isArabic = Get.locale?.languageCode == 'ar';
    final name = isArabic ? item.productNameAr : item.productNameEn;
    final total = formatStoreMinorMoney(
      item.lineTotalMinor,
      currencyCode,
      isArabic: isArabic,
    );

    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Text(
              name,
              style: const TextStyle(
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
          const SizedBox(width: 12),
          Text(
            '×${item.quantity} · $total',
            textAlign: TextAlign.end,
          ),
        ],
      ),
    );
  }
}

class _DetailRow extends StatelessWidget {
  const _DetailRow({
    required this.label,
    required this.value,
    this.emphasize = false,
  });

  final String label;
  final String value;
  final bool emphasize;

  @override
  Widget build(BuildContext context) {
    final style = emphasize
        ? const TextStyle(
            fontWeight: FontWeight.w700,
          )
        : null;

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 5),
      child: Row(
        children: [
          Expanded(
            child: Text(
              label,
              style: style,
            ),
          ),
          Text(
            value,
            style: style,
          ),
        ],
      ),
    );
  }
}

class _StatusChip extends StatelessWidget {
  const _StatusChip({
    required this.label,
    required this.positive,
  });

  final String label;
  final bool positive;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(
        horizontal: 9,
        vertical: 4,
      ),
      decoration: BoxDecoration(
        color: positive
            ? Colors.green.withValues(alpha: 0.1)
            : Colors.orange.withValues(alpha: 0.1),
        borderRadius: BorderRadius.circular(20),
      ),
      child: Text(
        label,
        style: TextStyle(
          color: positive ? Colors.green.shade800 : Colors.orange.shade900,
          fontSize: 12,
          fontWeight: FontWeight.w600,
        ),
      ),
    );
  }
}

class _EmptyOrders extends StatelessWidget {
  const _EmptyOrders();

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          children: [
            Icon(
              Icons.shopping_bag_outlined,
              size: 72,
              color: AppColor.primaryColor.withValues(alpha: 0.45),
            ),
            const SizedBox(height: 18),
            Text(
              PlatformOrders._label(
                ar: 'لا توجد طلبات محفوظة على هذا الجهاز بعد.',
                en: 'No orders are saved on this device yet.',
              ),
              textAlign: TextAlign.center,
              style: const TextStyle(
                fontSize: 17,
                fontWeight: FontWeight.w600,
              ),
            ),
            const SizedBox(height: 8),
            Text(
              PlatformOrders._label(
                ar: 'ستظهر هنا الطلبات التي يتم إنشاؤها عبر مسار الدفع الآمن.',
                en: 'Orders created through secure checkout will appear here.',
              ),
              textAlign: TextAlign.center,
              style: const TextStyle(
                color: Colors.black54,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _OrdersFailure extends StatelessWidget {
  const _OrdersFailure({
    required this.onRetry,
  });

  final Future<void> Function() onRetry;

  @override
  Widget build(BuildContext context) {
    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.all(32),
      children: [
        const SizedBox(height: 140),
        const Icon(
          Icons.cloud_off_outlined,
          size: 64,
          color: Colors.black45,
        ),
        const SizedBox(height: 16),
        Text(
          PlatformOrders._label(
            ar: 'تعذر تحميل الطلبات الآن.',
            en: 'Unable to load orders right now.',
          ),
          textAlign: TextAlign.center,
          style: const TextStyle(
            fontSize: 17,
            fontWeight: FontWeight.w600,
          ),
        ),
        const SizedBox(height: 16),
        Center(
          child: ElevatedButton.icon(
            onPressed: onRetry,
            icon: const Icon(Icons.refresh),
            label: Text(
              PlatformOrders._label(
                ar: 'إعادة المحاولة',
                en: 'Retry',
              ),
            ),
          ),
        ),
      ],
    );
  }
}
