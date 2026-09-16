<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateTenantStaff
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        // Never trust an authentication result cached from a
        // previous request in a long-lived application process.
        Auth::guard('sanctum')->forgetUser();

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
            ! $user->is_active ||
            (int) $user->tenant_id !==
                $this->tenantContext->requireId()
        ) {
            return ApiResponse::error(
                $request,
                'UNAUTHENTICATED',
                'Authentication is required.',
                401,
            );
        }

        $token = $user->currentAccessToken();

        if (
            $token === null ||
            ! $token->can('staff')
        ) {
            return ApiResponse::error(
                $request,
                'UNAUTHENTICATED',
                'Authentication is required.',
                401,
            );
        }

        return $next($request);
    }
}
