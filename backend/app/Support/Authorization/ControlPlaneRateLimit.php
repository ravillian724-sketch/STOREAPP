<?php

namespace App\Support\Authorization;

use App\Support\ApiResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ControlPlaneRateLimit
{
    public const NAME = 'control-plane';

    public static function limits(
        Request $request,
    ): array {
        $limit = max(
            1,
            (int) config(
                'control_plane.rate_limit_per_minute',
                30,
            ),
        );

        $ip = trim(
            (string) ($request->ip() ?? '')
        );

        if ($ip === '') {
            $ip = 'unknown';
        }

        $token = trim(
            (string) $request->bearerToken()
        );

        $tokenHash = $token === ''
            ? 'missing'
            : hash(
                'sha256',
                $token,
            );

        $response = fn (
            Request $request,
            array $headers,
        ): JsonResponse => self::tooManyAttempts(
            $request,
            $headers,
        );

        return [
            Limit::perMinute($limit)
                ->by(
                    hash(
                        'sha256',
                        implode(
                            '|',
                            [
                                self::NAME,
                                'ip',
                                $ip,
                            ],
                        ),
                    )
                )
                ->response($response),

            Limit::perMinute(
                min(
                    $limit,
                    10,
                )
            )
                ->by(
                    hash(
                        'sha256',
                        implode(
                            '|',
                            [
                                self::NAME,
                                'credential',
                                $ip,
                                $tokenHash,
                            ],
                        ),
                    )
                )
                ->response($response),
        ];
    }

    private static function tooManyAttempts(
        Request $request,
        array $headers,
    ): JsonResponse {
        $response = ApiResponse::error(
            $request,
            'CONTROL_PLANE_RATE_LIMITED',
            'Too many control plane requests.',
            429,
        );

        $response->headers->add(
            $headers
        );

        return $response;
    }
}
