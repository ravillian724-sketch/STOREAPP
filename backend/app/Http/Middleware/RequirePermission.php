<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    public function handle(
        Request $request,
        Closure $next,
        string $permission,
    ): Response {
        $permission = trim($permission);

        $user = $request->user('sanctum');

        if ($user === null) {
            return ApiResponse::error(
                $request,
                'UNAUTHENTICATED',
                'Authentication is required.',
                401,
            );
        }

        if (
            $permission === '' ||
            ! $user->hasPermission($permission)
        ) {
            return ApiResponse::error(
                $request,
                'FORBIDDEN',
                'Permission denied.',
                403,
            );
        }

        return $next($request);
    }
}
