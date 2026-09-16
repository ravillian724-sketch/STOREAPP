<?php

namespace App\Http\Middleware;

use App\Models\AppInstanceCredential;
use App\Support\ApiResponse;
use App\Support\AppInstance\AppInstanceToken;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ResolveAppInstanceMiddleware
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AppInstanceToken $tokenCodec,
    ) {}

    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        $this->tenantContext->clear();

        $rawToken = trim(
            (string) $request->header(
                'X-App-Instance-Key',
                '',
            )
        );

        if ($rawToken === '') {
            return ApiResponse::error(
                $request,
                'APP_INSTANCE_KEY_REQUIRED',
                'App instance key is required.',
                400,
            );
        }

        $parsed = $this->tokenCodec
            ->parse($rawToken);

        if ($parsed === null) {
            return $this->notFound($request);
        }

        $credential = AppInstanceCredential::query()
            ->with('appInstance.tenant')
            ->where(
                'public_id',
                $parsed['public_id'],
            )
            ->first();

        if (
            $credential === null ||
            $credential->revoked_at !== null ||
            $credential->valid_from === null ||
            $credential->valid_from->isFuture() ||
            (
                $credential->expires_at !== null &&
                ! $credential->expires_at->isFuture()
            ) ||
            ! $this->tokenCodec->verify(
                $parsed['secret'],
                $credential->secret_hash,
            )
        ) {
            return $this->notFound($request);
        }

        $instance = $credential->appInstance;

        if (
            $instance === null ||
            ! $instance->is_active ||
            $instance->tenant === null ||
            ! $instance->tenant->is_active
        ) {
            return $this->notFound($request);
        }

        $request->attributes->set(
            'app_instance_credential',
            $credential,
        );

        $request->attributes->set(
            'app_instance',
            $instance,
        );

        $request->attributes->set(
            'tenant',
            $instance->tenant,
        );

        $request->attributes->set(
            'tenant_id',
            (string) $instance->tenant_id,
        );

        $tenantId = (int) $instance->tenant_id;

        return DB::transaction(
            function () use (
                $request,
                $next,
                $tenantId,
            ): Response {
                $this->tenantContext->set(
                    $tenantId
                );

                try {
                    return $next($request);
                } finally {
                    $this->tenantContext->clear();
                }
            }
        );
    }

    private function notFound(
        Request $request,
    ): Response {
        return ApiResponse::error(
            $request,
            'APP_INSTANCE_NOT_FOUND',
            'Store configuration was not found.',
            404,
        );
    }
}
