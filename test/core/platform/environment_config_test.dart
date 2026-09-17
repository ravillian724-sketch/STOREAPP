import 'package:ecommerce_app/core/platform/environment_config.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  group('EnvironmentConfig', () {
    test('preserves an API base path while trimming trailing slashes', () {
      final uri = EnvironmentConfig.requireApiBaseUri(
        'https://api.example.test/platform///',
      );

      expect(uri.toString(), 'https://api.example.test/platform');
    });

    test('rejects missing API base URL', () {
      expect(
        () => EnvironmentConfig.requireApiBaseUri(''),
        throwsA(isA<StateError>()),
      );
    });

    test('rejects non HTTP schemes', () {
      expect(
        () => EnvironmentConfig.requireApiBaseUri('ftp://api.example.test'),
        throwsA(isA<StateError>()),
      );
    });
  });
}
