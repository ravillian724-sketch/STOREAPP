import 'package:ecommerce_app/core/class/statusrequest.dart';

import 'api_exception.dart';

StatusRequest statusRequestForError(Object error) {
  if (error is ApiNetworkException) {
    return StatusRequest.offlinefailuer;
  }

  if (error is ApiTimeoutException) {
    return StatusRequest.serverException;
  }

  if (error is ApiException) {
    final status = error.statusCode;

    if (status != null && status >= 500) {
      return StatusRequest.serverfailuer;
    }

    return StatusRequest.failure;
  }

  return StatusRequest.serverException;
}
