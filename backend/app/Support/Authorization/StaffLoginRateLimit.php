<?php

namespace App\Support\Authorization;

use App\Support\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StaffLoginRateLimit
{
    public const NAME = 'staff-login';

    private const PER_IDENTITY_PER_MINUTE = 5;

    private const PER_EMAIL_PER_MINUTE = 10;

    private const PER_TENANT_IP_PER_MINUTE = 120;

    public static function limits(
        Request $request,
        TenantContext $tenantContext,
    ): array {
        $tenantId = (string) $tenantContext->requireId();

        $email = strtolower(
            trim(
                (string) $request->input(
                    'email',
                    ''
                )
            )
        );

        if ($email === '') {
            $email = 'missing';
        }

        $ip = trim(
            (string) ($request->ip() ?? '')
        );

        if ($ip === '') {
            $ip = 'unknown';
        }

        $response = fn (
            Request $request,
            array $headers,
        ): JsonResponse => self::tooManyAttempts(
            $request,
            $headers,
        );

        return [
            Limit::perMinute(
                self::PER_IDENTITY_PER_MINUTE
            )
                ->by(
                    self::key(
                        'identity',
                        $tenantId,
                        $email,
                        $ip,
                    )
                )
                ->response($response),

            Limit::perMinute(
                self::PER_EMAIL_PER_MINUTE
            )
                ->by(
                    self::key(
                        'email',
                        $tenantId,
                        $email,
                    )
                )
                ->response($response),

            Limit::perMinute(
                self::PER_TENANT_IP_PER_MINUTE
            )
                ->by(
                    self::key(
                        'tenant-ip',
                        $tenantId,
                        $ip,
                    )
                )
                ->response($response),
        ];
    }

    private static function key(
        string $scope,
        string ...$parts,
    ): string {
        return hash(
            'sha256',
            implode(
                '|',
                [
                    self::NAME,
                    $scope,
                    ...$parts,
                ],
            ),
        );
    }

    private static function tooManyAttempts(
        Request $request,
        array $headers,
    ): JsonResponse {
        $response = ApiResponse::error(
            $request,
            'TOO_MANY_LOGIN_ATTEMPTS',
            'Too many login attempts.',
            429,
        );

        $response->headers->add($headers);

        return $response;
    }
}
