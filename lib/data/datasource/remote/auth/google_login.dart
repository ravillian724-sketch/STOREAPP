import 'package:ecommerce_app/core/class/crud.dart';
import 'package:ecommerce_app/linkapi.dart';

class GoogleLoginData {
  final Crud crud;

  GoogleLoginData(this.crud);

  Future<dynamic> postData(String firebaseIdToken) async {
    final response = await crud.postData(
      AppLink.googleLogin,
      {
        "firebase_id_token": firebaseIdToken,
      },
    );

    return response.fold(
      (left) => left,
      (right) => right,
    );
  }
}
