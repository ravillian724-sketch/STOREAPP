<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use App\Support\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateTenantCustomer
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function handle(
        Request $request,
        Closure $next,
        string $mode = 'required',
    ): Response {
        $optional = $mode === 'optional';

        $authorization = trim(
            (string) $request->header(
                'Authorization',
                '',
            )
        );

        if (
            $optional &&
            $authorization === ''
        ) {
            $request->attributes->set(
                'storefront_customer',
                null,
            );

            return $next($request);
        }

        // Long-lived application workers must never reuse
        // authentication state from a previous request.
        Auth::guard('sanctum')->forgetUser();

        $customer =
            $request->user('sanctum');

        if (
            ! $customer instanceof Customer ||
            ! $customer->is_active ||
            (int) $customer->tenant_id !==
                $this->tenantContext->requireId()
        ) {
            return $this->unauthenticated(
                $request
            );
        }

        $token =
            $customer->currentAccessToken();

        if (
            $token === null ||
            ! $token->can('customer')
        ) {
            return $this->unauthenticated(
                $request
            );
        }

        $request->attributes->set(
            'storefront_customer',
            $customer,
        );

        return $next($request);
    }

    private function unauthenticated(
        Request $request,
    ): Response {
        return ApiResponse::error(
            $request,
            'UNAUTHENTICATED',
            'Authentication is required.',
            401,
        );
    }
}
