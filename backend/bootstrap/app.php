<?php

use App\Http\Middleware\AuthenticateControlPlane;
use App\Http\Middleware\AuthenticateTenantCustomer;
use App\Http\Middleware\AuthenticateTenantStaff;
use App\Http\Middleware\RequestIdMiddleware;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\ResolveAppInstanceMiddleware;
use App\Http\Middleware\TenantBoundaryMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            RequestIdMiddleware::class,
        ]);

        // Trusted tenant resolution must happen before
        // the login rate limiter calculates tenant-scoped keys.
        $middleware->prependToPriorityList(
            before: [
                ThrottleRequests::class,
                ThrottleRequestsWithRedis::class,
            ],
            prepend: TenantBoundaryMiddleware::class,
        );

        $middleware->prependToPriorityList(
            before: TenantBoundaryMiddleware::class,
            prepend: ResolveAppInstanceMiddleware::class,
        );

        $middleware->alias([
            'app.instance' => ResolveAppInstanceMiddleware::class,
            'control.plane' => AuthenticateControlPlane::class,
            'tenant.boundary' => TenantBoundaryMiddleware::class,
            'tenant.staff' => AuthenticateTenantStaff::class,
            'tenant.customer' => AuthenticateTenantCustomer::class,
            'permission' => RequirePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
