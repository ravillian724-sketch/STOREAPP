<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TenantBoundaryMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $resolvedTenantId = $request->attributes->get('tenant_id');

        if ($resolvedTenantId === null) {
            return ApiResponse::error(
                $request,
                'TENANT_CONTEXT_REQUIRED',
                'Tenant context is required.',
                401,
            );
        }

        $claimedTenantId = trim((string) $request->header('X-Tenant-Id'));

        if (
            $claimedTenantId !== '' &&
            ! hash_equals((string) $resolvedTenantId, $claimedTenantId)
        ) {
            return ApiResponse::error(
                $request,
                'TENANT_CONTEXT_MISMATCH',
                'Tenant context mismatch.',
                403,
            );
        }

        return $next($request);
    }
}
