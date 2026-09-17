import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:dartz/dartz.dart';
import 'package:ecommerce_app/core/class/statusrequest.dart';
import 'package:ecommerce_app/core/functions/checkinternet.dart';
import 'package:http/http.dart' as http;

class Crud {
  static const Duration _requestTimeout = Duration(seconds: 20);

  Future<Either<StatusRequest, Map>> postData(
    String linkurl,
    Map data,
  ) async {
    try {
      final bool online = await checkInternet();

      if (!online) {
        return const Left(
          StatusRequest.offlinefailuer,
        );
      }

      final uri = Uri.tryParse(linkurl);

      if (uri == null || !(uri.scheme == "http" || uri.scheme == "https")) {
        return const Left(
          StatusRequest.serverException,
        );
      }

      final response = await http
          .post(
            uri,
            body: data,
          )
          .timeout(_requestTimeout);

      if (response.statusCode != 200 && response.statusCode != 201) {
        return const Left(
          StatusRequest.serverfailuer,
        );
      }

      final String body = response.body.trim();

      if (body.isEmpty) {
        return const Left(
          StatusRequest.serverfailuer,
        );
      }

      final dynamic decoded;

      try {
        decoded = jsonDecode(body);
      } on FormatException {
        return const Left(
          StatusRequest.serverfailuer,
        );
      }

      if (decoded is! Map) {
        return const Left(
          StatusRequest.serverfailuer,
        );
      }

      return Right(
        Map<String, dynamic>.from(decoded),
      );
    } on TimeoutException {
      return const Left(
        StatusRequest.serverException,
      );
    } on SocketException {
      return const Left(
        StatusRequest.offlinefailuer,
      );
    } on http.ClientException {
      return const Left(
        StatusRequest.serverException,
      );
    } catch (_) {
      return const Left(
        StatusRequest.serverException,
      );
    }
  }
}
