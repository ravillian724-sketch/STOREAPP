<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateControlPlane
{
    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        $configured = trim(
            (string) config(
                'control_plane.token',
                ''
            )
        );

        if (
            $configured === '' ||
            strlen($configured) < 32
        ) {
            return ApiResponse::error(
                $request,
                'CONTROL_PLANE_UNAVAILABLE',
                'Control plane is not configured.',
                503,
            );
        }

        $provided = trim(
            (string) $request->bearerToken()
        );

        if (
            $provided === '' ||
            ! hash_equals(
                $configured,
                $provided,
            )
        ) {
            return ApiResponse::error(
                $request,
                'UNAUTHENTICATED',
                'Authentication is required.',
                401,
            );
        }

        $request->attributes->set(
            'control_plane_authenticated',
            true,
        );

        return $next($request);
    }
}
