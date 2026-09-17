import 'package:cached_network_image/cached_network_image.dart';
import 'package:ecommerce_app/controller/home_controller.dart';
import 'package:ecommerce_app/core/class/handlingdataview.dart';
import 'package:ecommerce_app/core/constant/routes.dart';
import 'package:ecommerce_app/core/functions/storefront_display.dart';
import 'package:ecommerce_app/view/widget/home/customcardhome.dart';
import 'package:ecommerce_app/view/widget/home/customtitlehome.dart';
import 'package:ecommerce_app/view/widget/home/listcategorieshome.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../core/functions/translatedatabase.dart';
import '../../data/model/itemsmodel.dart';
import '../widget/customappbar.dart';
import '../widget/home/listitemshome.dart';
import 'chatbot.dart';

class HomePage extends GetView<HomeControllerImp> {
  const HomePage({super.key});

  @override
  Widget build(BuildContext context) {
    Get.put(HomeControllerImp());
    return GetBuilder<HomeControllerImp>(
      builder: (controller) => Stack(
        children: [
          Container(
            decoration: BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter,
                colors: [
                  Colors.blue[50]!,
                  Colors.grey[50]!,
                  Colors.white,
                ],
                stops: const [0.0, 0.3, 1.0],
              ),
            ),
            child: ListView(
              physics: const BouncingScrollPhysics(),
              children: [
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 17),
                  child: Column(
                    children: [
                      CustomAppBar(
                        mycontroller: controller.search!,
                        titleappbar: "51".tr,
                        onPressedSearch: () {
                          controller.onSearchItems();
                        },
                        onChanged: (val) {
                          controller.checkSearch(val);
                        },
                        onPressedIconFavorite: () {
                          Get.toNamed(AppRoute.myfavorite);
                        },
                      ),
                      HandlingDataView(
                        statusRequest: controller.statusRequest,
                        widget: !controller.isSearch
                            ? Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  const SizedBox(height: 15),
                                  CustomCardHome(
                                      title: controller.titelhomeCard,
                                      body: controller.bodyhomeCard),
                                  if (controller.categories.isNotEmpty) ...[
                                    const SizedBox(height: 20),
                                    CustomTitleHome(title: "54".tr),
                                    const ListCategoriesHome(),
                                    const SizedBox(height: 10),
                                  ],
                                  if (controller.items.isNotEmpty) ...[
                                    CustomTitleHome(title: "Top Selling".tr),
                                    const ListItemsHome(),
                                  ],
                                ],
                              )
                            : ListItemsSearch(
                                listdatamodel: controller.listdata),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
          const Positioned(child: ChatBotBubble())
        ],
      ),
    );
  }
}

class ListItemsSearch extends GetView<HomeControllerImp> {
  final List<ItemsModel> listdatamodel;
  const ListItemsSearch({super.key, required this.listdatamodel});

  @override
  Widget build(BuildContext context) {
    return ListView.builder(
      itemCount: listdatamodel.length,
      shrinkWrap: true,
      physics: const BouncingScrollPhysics(),
      itemBuilder: (context, index) {
        final item = listdatamodel[index];
        final imageUrl = resolveProductImageUrl(item.itemsImage);
        final isArabic = Get.locale?.languageCode == 'ar';

        return TweenAnimationBuilder(
          duration: Duration(milliseconds: 200 + (index * 100)),
          tween: Tween<double>(begin: 0, end: 1),
          builder: (context, double value, child) {
            return Transform.scale(
              scale: value,
              child: Container(
                margin: const EdgeInsets.symmetric(vertical: 10, horizontal: 5),
                child: Card(
                  elevation: 5,
                  shadowColor: Colors.blue.withValues(alpha: 0.2),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(20),
                    side: BorderSide(color: Colors.grey.shade200),
                  ),
                  child: InkWell(
                    borderRadius: BorderRadius.circular(20),
                    onTap: () {
                      controller.goToPageProductDetails(item);
                    },
                    child: Padding(
                      padding: const EdgeInsets.all(12),
                      child: Row(
                        children: [
                          Container(
                            width: 120,
                            height: 120,
                            decoration: BoxDecoration(
                              borderRadius: BorderRadius.circular(15),
                            ),
                            child: ClipRRect(
                              borderRadius: BorderRadius.circular(15),
                              child: imageUrl == null
                                  ? Container(
                                      color: Colors.grey[100],
                                      alignment: Alignment.center,
                                      child: Icon(
                                        Icons.medication_outlined,
                                        color: Colors.blueGrey[300],
                                        size: 44,
                                      ),
                                    )
                                  : CachedNetworkImage(
                                      imageUrl: imageUrl,
                                      fit: BoxFit.cover,
                                      placeholder: (context, url) => Container(
                                        color: Colors.grey[100],
                                        child: const Center(
                                          child: CircularProgressIndicator(),
                                        ),
                                      ),
                                      errorWidget: (context, url, error) =>
                                          Container(
                                        color: Colors.grey[100],
                                        alignment: Alignment.center,
                                        child: Icon(
                                          Icons.medication_outlined,
                                          color: Colors.blueGrey[300],
                                          size: 44,
                                        ),
                                      ),
                                    ),
                            ),
                          ),
                          const SizedBox(width: 15),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  translateDatabase(
                                    item.itemsNameAr,
                                    item.itemsName,
                                  ),
                                  style: const TextStyle(
                                    fontSize: 20,
                                    fontWeight: FontWeight.bold,
                                    color: Colors.black87,
                                    letterSpacing: 0.5,
                                  ),
                                  maxLines: 2,
                                  overflow: TextOverflow.ellipsis,
                                ),
                                const SizedBox(height: 8),
                                Text(
                                  formatStoreMoney(
                                    item.itemsPrice,
                                    item.currencyCode,
                                    isArabic: isArabic,
                                  ),
                                  style: TextStyle(
                                    color: Colors.blue[800],
                                    fontSize: 16,
                                    fontWeight: FontWeight.w700,
                                  ),
                                ),
                                if (item.availableToSell != null) ...[
                                  const SizedBox(height: 6),
                                  Text(
                                    isArabic
                                        ? "متوفر: ${item.availableToSell}"
                                        : "Available: ${item.availableToSell}",
                                    style: const TextStyle(
                                      fontSize: 13,
                                      color: Colors.black54,
                                    ),
                                  ),
                                ],
                                if (item.subcategoryName?.trim().isNotEmpty ==
                                    true) ...[
                                  const SizedBox(height: 8),
                                  Text(
                                    item.subcategoryName!,
                                    style: const TextStyle(
                                      fontSize: 13,
                                      color: Colors.black54,
                                    ),
                                  ),
                                ],
                                if (item.itemsScientificformula
                                        ?.trim()
                                        .isNotEmpty ==
                                    true) ...[
                                  const SizedBox(height: 6),
                                  Text(
                                    item.itemsScientificformula!,
                                    style: const TextStyle(
                                      fontSize: 13,
                                      color: Colors.black54,
                                    ),
                                    maxLines: 2,
                                    overflow: TextOverflow.ellipsis,
                                  ),
                                ],
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
            );
          },
        );
      },
    );
  }
}
