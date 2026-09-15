import 'package:flutter/foundation.dart';

enum CommerceChannel {
  android,
  ios,
  web,
  unknown,
}

class ChannelContext {
  ChannelContext._();

  static CommerceChannel get current {
    if (kIsWeb) {
      return CommerceChannel.web;
    }

    switch (defaultTargetPlatform) {
      case TargetPlatform.android:
        return CommerceChannel.android;
      case TargetPlatform.iOS:
        return CommerceChannel.ios;
      default:
        return CommerceChannel.unknown;
    }
  }

  static String get code => current.name;
}
