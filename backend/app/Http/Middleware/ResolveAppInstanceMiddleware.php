<?php

namespace App\Http\Middleware;

use App\Models\AppInstance;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveAppInstanceMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->input('app_instance_key'));

        if ($key === '') {
            return ApiResponse::error(
                $request,
                'APP_INSTANCE_KEY_REQUIRED',
                'App instance key is required.',
                400,
            );
        }

        $instance = AppInstance::query()
            ->with('tenant')
            ->where('key_hash', hash('sha256', $key))
            ->where('is_active', true)
            ->first();

        if (
            $instance === null ||
            $instance->tenant === null ||
            ! $instance->tenant->is_active
        ) {
            return ApiResponse::error(
                $request,
                'APP_INSTANCE_NOT_FOUND',
                'Store configuration was not found.',
                404,
            );
        }

        $request->attributes->set('app_instance', $instance);
        $request->attributes->set('tenant', $instance->tenant);
        $request->attributes->set('tenant_id', (string) $instance->tenant_id);

        return $next($request);
    }
}
