import 'package:cached_network_image/cached_network_image.dart';
import 'package:ecommerce_app/controller/myfavorite_controller.dart';
import 'package:ecommerce_app/core/functions/storefront_display.dart';
import 'package:ecommerce_app/data/model/myfavorite.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../../core/constant/color.dart';
import '../../../core/functions/responsivehelper.dart';
import '../../../core/functions/translatedatabase.dart';

class CustomListFavoriteItems extends GetView<MyFavoriteController> {
  final MyFavoriteModel itemsModel;

  const CustomListFavoriteItems({
    super.key,
    required this.itemsModel,
  });

  @override
  Widget build(BuildContext context) {
    final imageUrl = resolveProductImageUrl(itemsModel.itemsImage);

    return InkWell(
      child: Card(
        child: Padding(
          padding: EdgeInsets.all(Responsive.padding(10)),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.center,
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Hero(
                tag: "${itemsModel.itemsId}",
                child: imageUrl == null
                    ? Container(
                        height: Responsive.h(180),
                        color: Colors.grey[100],
                        alignment: Alignment.center,
                        child: Icon(
                          Icons.medication_outlined,
                          color: Colors.blueGrey[300],
                          size: Responsive.icon(64),
                        ),
                      )
                    : CachedNetworkImage(
                        imageUrl: imageUrl,
                        height: Responsive.h(180),
                        fit: BoxFit.fill,
                        errorWidget: (context, url, error) => Container(
                          height: Responsive.h(180),
                          color: Colors.grey[100],
                          alignment: Alignment.center,
                          child: Icon(
                            Icons.medication_outlined,
                            color: Colors.blueGrey[300],
                            size: Responsive.icon(64),
                          ),
                        ),
                      ),
              ),
              SizedBox(
                height: Responsive.h(10),
              ),
              Text(
                translateDatabase(itemsModel.itemsNameAr, itemsModel.itemsName),
                style: TextStyle(
                    color: AppColor.black,
                    fontSize: Responsive.font(32),
                    fontWeight: FontWeight.bold),
              ),
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  const Text(
                    "Rating 3.5",
                    textAlign: TextAlign.center,
                  ),
                  Container(
                    alignment: Alignment.bottomCenter,
                    height: Responsive.h(22),
                    child: Row(
                      children: [
                        ...List.generate(
                            5,
                            (index) => Icon(
                                  Icons.star,
                                  size: Responsive.icon(28),
                                ))
                      ],
                    ),
                  )
                ],
              ),
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text(
                    "${itemsModel.itemsPrice!.round()}\$",
                    style: TextStyle(
                        fontSize: Responsive.font(30),
                        color: AppColor.primaryColor,
                        fontFamily: "sans"),
                  ),
                  IconButton(
                      onPressed: () {
                        controller.deleteFromFavorite(
                            itemsModel.favoriteId!.toString());
                      },
                      icon: const Icon(Icons.delete_outline_outlined)),
                ],
              )
            ],
          ),
        ),
      ),
    );
  }
}
