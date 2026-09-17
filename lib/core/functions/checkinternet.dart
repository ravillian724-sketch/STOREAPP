import 'dart:async';
import 'dart:io';

Future<bool> checkInternet() async {
  try {
    final result = await InternetAddress.lookup(
      "google.com",
    ).timeout(const Duration(seconds: 5));

    return result.isNotEmpty && result.first.rawAddress.isNotEmpty;
  } on TimeoutException {
    return false;
  } on SocketException {
    return false;
  } catch (_) {
    return false;
  }
}
