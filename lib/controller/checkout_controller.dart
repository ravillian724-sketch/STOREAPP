import 'package:ecommerce_app/core/class/statusrequest.dart';
import 'package:ecommerce_app/core/constant/routes.dart';
import 'package:ecommerce_app/core/services/services.dart';
import 'package:ecommerce_app/data/datasource/remote/address_data.dart';
import 'package:ecommerce_app/data/datasource/remote/checkout_data.dart';
import 'package:ecommerce_app/data/datasource/remote/medicalinfo_data.dart';
import 'package:ecommerce_app/data/model/addressmodel.dart';
import 'package:ecommerce_app/data/model/medicalinfomodel.dart';
import 'package:flutter/cupertino.dart';
import 'package:get/get.dart';

import '../core/functions/handlingdatacontroller.dart';
import '../core/services/notification_service.dart';
import 'package:ecommerce_app/core/functions/session_guard.dart';

import 'package:ecommerce_app/core/logging/app_logger.dart';

class CheckoutController extends GetxController {
  AddressData addressData = Get.put(AddressData(Get.find()));

  CheckoutData checkoutData = Get.put(CheckoutData(Get.find()));

  MedicalInfoData medicalInfoData = Get.put(MedicalInfoData(Get.find()));

  NotificationService notificationService = Get.find<NotificationService>();

  MyServices myServices = Get.find();

  StatusRequest statusRequest = StatusRequest.none;

  List<AddressModel> dataaddress = [];

  String? paymentMethod;
  String? deliveryType;
  String addressId = "0";

  late String couponid;
  late String coupondiscount;
  late String priceorders;

  TextEditingController diseasesController = TextEditingController();
  TextEditingController medicationsController = TextEditingController();
  TextEditingController notesController = TextEditingController();

  TextEditingController ageController = TextEditingController();
  TextEditingController heightController = TextEditingController();
  TextEditingController weightController = TextEditingController();
  TextEditingController allergiesController = TextEditingController();

  String? selectedGender;
  String? selectedBloodType;

  void updateGender(String? value) {
    selectedGender = value;
    update();
  }

  void updateBloodType(String? value) {
    selectedBloodType = value;
    update();
  }

  choosePaymentMethod(String val) {
    paymentMethod = val;
    update();
  }

  chooseDeliveryType(String val) {
    deliveryType = val;
    update();
  }

  chooseShippingAddress(String val) {
    addressId = val;
    update();
  }

  getShippingAddress() async {
    final userId = await requireUserId(myServices);
    if (userId == null) {
      return;
    }

    statusRequest = StatusRequest.loading;

    var response = await addressData.getData(userId);

    appDebugLog(
        "========================================Controller  $response");

    statusRequest = handlingData(response);

    if (StatusRequest.success == statusRequest) {
      if (response['status'] == "success") {
        List listdata = response['data'];
        dataaddress.clear();
        dataaddress.addAll(
          listdata.map((e) => AddressModel.fromJson(e)),
        );

        if (dataaddress.isNotEmpty) {
          addressId = dataaddress.first.addressId?.toString() ?? "0";
        } else {
          addressId = "0";
        }
      } else {
        statusRequest = StatusRequest.success;
        //   notificationService.showErrorNotification(title: "71".tr, message: "NO Address".tr);
      }
    }
    update();
  }

  bool validateMedicalInfo() {
    if (ageController.text.isNotEmpty) {
      int? age = int.tryParse(ageController.text);
      if (age == null || age <= 0 || age > 120) {
        notificationService.showErrorNotification(
            title: "Invalid Age",
            message: "Please enter a valid age between 1 and 120");
        return false;
      }
    }

    if (heightController.text.isNotEmpty) {
      int? height = int.tryParse(heightController.text);
      if (height == null || height <= 0 || height > 250) {
        notificationService.showErrorNotification(
            title: "Invalid Height",
            message: "Please enter a valid height in cm (1-250)");
        return false;
      }
    }

    if (weightController.text.isNotEmpty) {
      int? weight = int.tryParse(weightController.text);
      if (weight == null || weight <= 0 || weight > 300) {
        notificationService.showErrorNotification(
            title: "Invalid Weight",
            message: "Please enter a valid weight in kg (1-300)");
        return false;
      }
    }

    if (selectedBloodType != null) {
      final validBloodTypes = [
        'A+',
        'A-',
        'B+',
        'B-',
        'AB+',
        'AB-',
        'O+',
        'O-'
      ];
      if (!validBloodTypes.contains(selectedBloodType)) {
        notificationService.showErrorNotification(
            title: "Invalid Blood Type",
            message: "Please select a valid blood type");
        return false;
      }
    }

    if (selectedGender != null) {
      final validGenders = ['Male', 'Female'];
      if (!validGenders.contains(selectedGender)) {
        notificationService.showErrorNotification(
            title: "Invalid Gender", message: "Please select a valid gender");
        return false;
      }
    }

    return true;
  }

  Future<void> useMedicalProfile() async {
    final userId = await requireUserId(myServices);
    if (userId == null) {
      return;
    }
    try {
      statusRequest = StatusRequest.loading;
      update();

      var response = await medicalInfoData.getData(userId);

      if (response['status'] == "success" && response['data'].isNotEmpty) {
        var medicalInfo = MedicalInfoModel.fromJson(response['data'][0]);

        if (medicalInfo.medicalInfoAge != null) {
          ageController.text = medicalInfo.medicalInfoAge.toString();
        }
        if (medicalInfo.medicalInfoHeight != null) {
          heightController.text = medicalInfo.medicalInfoHeight.toString();
        }
        if (medicalInfo.medicalInfoWeight != null) {
          weightController.text = medicalInfo.medicalInfoWeight.toString();
        }
        if (medicalInfo.medicalInfoGender != null) {
          selectedGender = medicalInfo.medicalInfoGender;
        }
        if (medicalInfo.medicalInfoBloodType != null) {
          selectedBloodType = medicalInfo.medicalInfoBloodType;
        }
        if (medicalInfo.medicalInfoChronicDiseases != null) {
          diseasesController.text = medicalInfo.medicalInfoChronicDiseases!;
        }
        if (medicalInfo.medicalInfoAllergies != null) {
          allergiesController.text = medicalInfo.medicalInfoAllergies!;
        }
        if (medicalInfo.medicalInfoCurrentMedications != null) {
          medicationsController.text =
              medicalInfo.medicalInfoCurrentMedications!;
        }
        if (medicalInfo.medicalInfoAdditionalNotes != null) {
          notesController.text = medicalInfo.medicalInfoAdditionalNotes!;
        }

        notificationService.showSuccessNotification(
            title: "Success", message: "Medical profile loaded successfully");
      } else {
        notificationService.showErrorNotification(
            title: "No Medical Profile",
            message:
                "No medical profile found. You can fill the information manually.");
      }
    } catch (e) {
      appDebugLog("Error loading medical profile: $e");
      notificationService.showErrorNotification(
          title: "Error",
          message: "Failed to load medical profile. Please try again.");
    } finally {
      statusRequest = StatusRequest.none;
      update();
    }
  }

  checkout(String diseases, String medications, String notes) async {
    final userId = await requireUserId(myServices);
    if (userId == null) {
      return;
    }
    if (paymentMethod == null) {
      return notificationService.showErrorNotification(
          title: "71".tr, message: "Please Choose Payment Method".tr);
    }
    if (deliveryType == null) {
      return notificationService.showErrorNotification(
          title: "71".tr, message: "Please Choose Delivery Type".tr);
    }
    if (dataaddress.isEmpty) {
      return notificationService.showErrorNotification(
          title: "71".tr, message: "Please Select Address".tr);
    }

    if (!validateMedicalInfo()) {
      return;
    }

    statusRequest = StatusRequest.loading;
    update();

    try {
      Map data = {
        "usersid": userId,
        "addressid": addressId.toString(),
        "orderstype": deliveryType.toString(),
        "pricedelivery": deliveryType.toString() == "0" ? "10000" : "0",
        "ordersprice": priceorders.toString(),
        "couponid": couponid.toString(),
        "coupondiscount": coupondiscount.toString(),
        "paymentmethod": paymentMethod.toString(),
        "orders_diseases": diseases.trim(),
        "orders_medications": medications.trim(),
        "orders_doctornotes": notes.trim(),
        "orders_age": ageController.text.trim(),
        "orders_height": heightController.text.trim(),
        "orders_weight": weightController.text.trim(),
        "orders_gender": selectedGender,
        "orders_blood_type": selectedBloodType,
        "orders_allergies": allergiesController.text.trim(),
      };

      var response = await checkoutData.checkoutData(data);
      appDebugLog(
          "========================================Controller  $response");

      statusRequest = handlingData(response);

      if (StatusRequest.success == statusRequest) {
        if (response['status'] == "success") {
          Get.offAllNamed(AppRoute.homepage);
          notificationService.showSuccessNotification(
              title: "success".tr, message: "Order Submitted Successfully!");
        } else {
          statusRequest = StatusRequest.none;
          notificationService.showErrorNotification(
              title: "71".tr, message: "Fail, Please Try Again!".tr);
        }
      }
    } catch (e) {
      appDebugLog("Error in checkout: $e");
      notificationService.showErrorNotification(
          title: "Error",
          message:
              "An error occurred while processing your order. Please try again.");
      statusRequest = StatusRequest.failure;
    } finally {
      update();
    }
  }

  @override
  void onInit() {
    final arguments = Get.arguments;

    if (arguments is Map) {
      couponid = arguments["couponid"]?.toString() ?? "0";
      priceorders = arguments["priceorders"]?.toString() ?? "0";
      coupondiscount = arguments["discountcoupon"]?.toString() ?? "0";
    } else {
      couponid = "0";
      priceorders = "0";
      coupondiscount = "0";
    }

    getShippingAddress();
    super.onInit();
  }

  @override
  void onClose() {
    diseasesController.dispose();
    medicationsController.dispose();
    notesController.dispose();
    ageController.dispose();
    heightController.dispose();
    weightController.dispose();
    allergiesController.dispose();
    super.onClose();
  }

  @override
  void dispose() {
    diseasesController.dispose();
    medicationsController.dispose();
    notesController.dispose();
    ageController.dispose();
    heightController.dispose();
    weightController.dispose();
    allergiesController.dispose();
    super.dispose();
  }
}
