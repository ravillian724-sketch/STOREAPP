import 'dart:convert';

import 'package:ecommerce_app/core/network/api_exception.dart';
import 'package:ecommerce_app/core/platform/bootstrap_service.dart';
import 'package:ecommerce_app/core/platform/environment_config.dart';
import 'package:ecommerce_app/core/platform/startup_performance_policy.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  group('BootstrapService', () {
    test('keeps production and staging startup budgets strict', () {
      for (final environment in <AppEnvironment>[
        AppEnvironment.production,
        AppEnvironment.staging,
      ]) {
        expect(
          StartupPerformancePolicy.bootstrapNetworkTimeoutFor(environment),
          const Duration(seconds: 5),
        );
        expect(
          StartupPerformancePolicy.criticalPreparationTimeoutFor(environment),
          const Duration(seconds: 3),
        );
      }
    });

    test('allows development cold-start overhead without weakening production',
        () {
      expect(
        StartupPerformancePolicy.bootstrapNetworkTimeoutFor(
          AppEnvironment.development,
        ),
        const Duration(seconds: 30),
      );
      expect(
        StartupPerformancePolicy.criticalPreparationTimeoutFor(
          AppEnvironment.development,
        ),
        const Duration(seconds: 10),
      );
    });

    test('sends app instance key and parses a valid bootstrap response',
        () async {
      late http.Request capturedRequest;

      final client = MockClient((request) async {
        capturedRequest = request;

        return http.Response(
          jsonEncode({
            'data': {
              'store': {
                'tenant_id': 'tenant-1',
                'name_ar': 'متجر',
                'name_en': 'Store',
                'features': {
                  'tabby': true,
                },
              },
              'default_branch_id': 'branch-1',
            },
          }),
          200,
          headers: const {
            'content-type': 'application/json',
          },
        );
      });

      final service = BootstrapService(
        client: client,
        appInstanceKey: 'test-instance',
        apiBaseUrl: 'https://api.example.test/platform',
        timeout: const Duration(seconds: 1),
      );

      addTearDown(service.close);

      final result = await service.load();

      expect(result.storeConfig.tenantId, 'tenant-1');
      expect(result.storeConfig.features.tabby, isTrue);
      expect(result.defaultBranchId, 'branch-1');

      final requestBody =
          jsonDecode(capturedRequest.body) as Map<String, dynamic>;

      expect(
        capturedRequest.headers['X-App-Instance-Key'],
        'test-instance',
      );
      expect(
        capturedRequest.url.toString(),
        'https://api.example.test/platform/api/v1/bootstrap',
      );
      expect(
        requestBody.containsKey('app_instance_key'),
        isFalse,
      );
      expect(
        requestBody['channel'],
        isA<String>(),
      );
    });

    test('rejects non-object feature flags as an API contract error', () async {
      final client = MockClient((request) async {
        return http.Response(
          jsonEncode({
            'data': {
              'store': {
                'tenant_id': 'tenant-1',
                'name_ar': 'متجر',
                'name_en': 'Store',
                'features': <dynamic>[],
              },
              'default_branch_id': 'branch-1',
            },
          }),
          200,
          headers: const {
            'content-type': 'application/json; charset=utf-8',
          },
        );
      });

      final service = BootstrapService(
        client: client,
        appInstanceKey: 'test-instance',
        apiBaseUrl: 'https://api.example.test',
        timeout: const Duration(seconds: 1),
      );

      addTearDown(service.close);

      await expectLater(
        service.load(),
        throwsA(
          isA<ApiException>()
              .having(
                  (error) => error, 'type', isNot(isA<ApiNetworkException>()))
              .having(
                (error) => error.message,
                'message',
                'Store features must be a JSON object.',
              ),
        ),
      );
    });

    test('converts bootstrap deadline expiration into ApiTimeoutException',
        () async {
      final client = MockClient((request) async {
        await Future<void>.delayed(
          const Duration(milliseconds: 100),
        );

        return http.Response('{}', 200);
      });

      final service = BootstrapService(
        client: client,
        appInstanceKey: 'test-instance',
        apiBaseUrl: 'https://api.example.test',
        timeout: const Duration(milliseconds: 10),
      );

      addTearDown(service.close);

      await expectLater(
        service.load(),
        throwsA(isA<ApiTimeoutException>()),
      );
    });
  });
}
