import 'package:ecommerce_app/core/class/statusrequest.dart';
import 'package:ecommerce_app/core/functions/handlingdatacontroller.dart';
import 'package:ecommerce_app/core/functions/session_guard.dart';
import 'package:ecommerce_app/core/logging/app_logger.dart';
import 'package:ecommerce_app/core/network/api_exception.dart';
import 'package:ecommerce_app/core/network/status_request_mapper.dart';
import 'package:ecommerce_app/core/services/notification_service.dart';
import 'package:ecommerce_app/core/services/services.dart';
import 'package:ecommerce_app/data/datasource/remote/cart_data.dart';
import 'package:ecommerce_app/data/datasource/remote/storefront_cart_data.dart';
import 'package:ecommerce_app/data/model/itemsmodel.dart';
import 'package:ecommerce_app/data/model/storefront_cart_model.dart';
import 'package:get/get.dart';

abstract class ProductdetailsController extends GetxController {}

class ProductDetailsControllerImp extends ProductdetailsController {
  ProductDetailsControllerImp({
    StorefrontCartData? platformCartData,
  }) : platformCartData = platformCartData ?? StorefrontCartData();

  NotificationService get notificationService =>
      Get.find<NotificationService>();

  final StorefrontCartData platformCartData;

  CartData cartData = CartData(Get.find());
  MyServices myServices = Get.find();

  late ItemsModel itemsModel;

  StatusRequest statusRequest = StatusRequest.none;

  int countitems = 0;

  Future<void> initialData() async {
    statusRequest = StatusRequest.loading;
    update();

    try {
      final arguments = Get.arguments;

      if (arguments is! Map || arguments['itemsModel'] is! ItemsModel) {
        throw StateError(
          'Product details require an ItemsModel argument.',
        );
      }

      itemsModel = arguments['itemsModel'] as ItemsModel;

      if (itemsModel.platformManaged) {
        countitems = await platformCartData.quantityForSku(
          _platformSkuId(),
        );
      } else {
        countitems = await _legacyCountItems(
              itemsModel.itemsId?.toString() ?? '',
            ) ??
            0;
      }

      statusRequest = StatusRequest.success;
    } catch (error) {
      statusRequest = statusRequestForError(error);
    }

    update();
  }

  Future<void> add() async {
    if (!itemsModel.platformManaged) {
      await _legacyAdd();
      return;
    }

    statusRequest = StatusRequest.loading;
    update();

    try {
      final snapshot = await platformCartData.incrementSku(
        _platformSkuId(),
      );

      countitems = _quantityFrom(
        snapshot,
      );

      statusRequest = StatusRequest.success;

      notificationService.showSuccessNotification(
        title: '71'.tr,
        message: '73'.tr,
      );
    } catch (error) {
      statusRequest = statusRequestForError(error);

      _showPlatformError(error);
    }

    update();
  }

  Future<void> remove() async {
    if (!itemsModel.platformManaged) {
      await _legacyRemove();
      return;
    }

    statusRequest = StatusRequest.loading;
    update();

    try {
      final snapshot = await platformCartData.decrementSku(
        _platformSkuId(),
      );

      countitems = _quantityFrom(
        snapshot,
      );

      statusRequest = StatusRequest.success;
    } catch (error) {
      statusRequest = statusRequestForError(error);

      _showPlatformError(error);
    }

    update();
  }

  int _quantityFrom(
    StorefrontCartSnapshot snapshot,
  ) {
    final skuId = _platformSkuId();

    for (final item in snapshot.items) {
      if (item.skuId == skuId) {
        return item.quantity;
      }
    }

    return 0;
  }

  String _platformSkuId() {
    final skuId = itemsModel.platformSkuId?.trim() ?? '';

    if (skuId.isEmpty) {
      throw StateError(
        'Platform product is missing its SKU identity.',
      );
    }

    return skuId;
  }

  void _showPlatformError(
    Object error,
  ) {
    var message = _localized(
      ar: 'تعذر تحديث السلة. حاول مرة أخرى.',
      en: 'Unable to update the cart. Please try again.',
    );

    if (error is ApiNetworkException) {
      message = _localized(
        ar: 'تعذر الاتصال بالخدمة. تحقق من الإنترنت.',
        en: 'Unable to reach the service. Check your internet connection.',
      );
    } else if (error is ApiException) {
      final code = _apiErrorCode(error);

      if (code == 'INSUFFICIENT_STOCK') {
        message = _localized(
          ar: 'الكمية المطلوبة غير متاحة حاليًا.',
          en: 'The requested quantity is not currently available.',
        );
      }
    }

    notificationService.showErrorNotification(
      title: '71'.tr,
      message: message,
    );
  }

  String? _apiErrorCode(
    ApiException error,
  ) {
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

  String _localized({
    required String ar,
    required String en,
  }) {
    return Get.locale?.languageCode == 'ar' ? ar : en;
  }

  Future<int?> _legacyCountItems(
    String itemsid,
  ) async {
    if (itemsid.trim().isEmpty) {
      return 0;
    }

    final userId = await requireUserId(
      myServices,
    );

    if (userId == null) {
      return 0;
    }

    final response = await cartData.getCountCart(
      userId,
      itemsid,
    );

    appDebugLog(
      '========================================Controller  $response',
    );

    statusRequest = handlingData(response);

    if (StatusRequest.success == statusRequest &&
        response['status'] == 'success') {
      return int.tryParse(
            response['data'].toString(),
          ) ??
          0;
    }

    return 0;
  }

  Future<void> _legacyAdd() async {
    final itemsId = itemsModel.itemsId?.toString();

    if (itemsId == null) {
      return;
    }

    final userId = await requireUserId(
      myServices,
    );

    if (userId == null) {
      return;
    }

    statusRequest = StatusRequest.loading;
    update();

    final response = await cartData.addCart(
      userId,
      itemsId,
    );

    statusRequest = handlingData(response);

    if (StatusRequest.success == statusRequest &&
        response['status'] == 'success') {
      countitems += 1;

      notificationService.showSuccessNotification(
        title: '71'.tr,
        message: '73'.tr,
      );
    } else {
      statusRequest = StatusRequest.failure;
    }

    update();
  }

  Future<void> _legacyRemove() async {
    if (countitems <= 0) {
      return;
    }

    final itemsId = itemsModel.itemsId?.toString();

    if (itemsId == null) {
      return;
    }

    final userId = await requireUserId(
      myServices,
    );

    if (userId == null) {
      return;
    }

    statusRequest = StatusRequest.loading;
    update();

    final response = await cartData.deleteCart(
      userId,
      itemsId,
    );

    statusRequest = handlingData(response);

    if (StatusRequest.success == statusRequest &&
        response['status'] == 'success') {
      countitems -= 1;
    } else {
      statusRequest = StatusRequest.failure;
    }

    update();
  }

  List subitems = [
    {
      'name': '65'.tr,
      'id': 1,
      'active': 0,
    },
    {
      'name': '67'.tr,
      'id': 2,
      'active': 1,
    },
    {
      'name': '66'.tr,
      'id': 3,
      'active': 0,
    },
  ];

  @override
  void onInit() {
    initialData();
    super.onInit();
  }
}
