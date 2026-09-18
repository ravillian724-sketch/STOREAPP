import 'package:cached_network_image/cached_network_image.dart';
import 'package:ecommerce_app/controller/productdetails_controller.dart';
import 'package:ecommerce_app/core/constant/color.dart';
import 'package:ecommerce_app/core/functions/storefront_display.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';

class TopProductPageDetails extends GetView<ProductDetailsControllerImp> {
  const TopProductPageDetails({
    super.key,
  });

  @override
  Widget build(BuildContext context) {
    final imageUrl = resolveProductImageUrl(
      controller.itemsModel.itemsImage,
    );

    final heroTag = controller.itemsModel.platformProductId ??
        controller.itemsModel.itemsId?.toString() ??
        controller.itemsModel.platformSkuId ??
        'product-image';

    return Stack(
      clipBehavior: Clip.none,
      children: [
        Container(
          height: 180,
          decoration: const BoxDecoration(
            color: AppColor.secondColor,
          ),
        ),
        Positioned(
          right: Get.width / 8,
          left: Get.width / 8,
          top: 30,
          child: Hero(
            tag: heroTag,
            child: imageUrl == null
                ? Container(
                    height: 250,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                      color: Colors.white,
                      borderRadius: BorderRadius.circular(
                        18,
                      ),
                    ),
                    child: Icon(
                      Icons.medication_outlined,
                      size: 88,
                      color: Colors.blueGrey[300],
                    ),
                  )
                : CachedNetworkImage(
                    imageUrl: imageUrl,
                    height: 250,
                    fit: BoxFit.contain,
                    placeholder: (context, url) => const Center(
                      child: CircularProgressIndicator(),
                    ),
                    errorWidget: (context, url, error) => Container(
                      color: Colors.white,
                      alignment: Alignment.center,
                      child: Icon(
                        Icons.medication_outlined,
                        size: 88,
                        color: Colors.blueGrey[300],
                      ),
                    ),
                  ),
          ),
        ),
      ],
    );
  }
}
