import 'dart:convert';

import 'package:ecommerce_app/core/network/api_exception.dart';
import 'package:ecommerce_app/core/platform/bootstrap_service.dart';
import 'package:ecommerce_app/core/platform/startup_performance_policy.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  group('BootstrapService', () {
    test('uses the centralized startup network budget', () {
      expect(
        StartupPerformancePolicy.bootstrapNetworkTimeout,
        const Duration(seconds: 5),
      );
      expect(
        StartupPerformancePolicy.criticalPreparationTimeout,
        const Duration(seconds: 3),
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
        timeout: const Duration(seconds: 1),
      );

      addTearDown(service.close);

      final result = await service.load();

      expect(result.storeConfig.tenantId, 'tenant-1');
      expect(result.defaultBranchId, 'branch-1');

      final requestBody =
          jsonDecode(capturedRequest.body) as Map<String, dynamic>;

      expect(
        capturedRequest.headers['X-App-Instance-Key'],
        'test-instance',
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
