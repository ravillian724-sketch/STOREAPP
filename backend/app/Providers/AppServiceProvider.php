<?php

namespace App\Providers;

use App\Support\AppInstance\AppInstanceToken;
use App\Support\Authorization\ControlPlaneRateLimit;
use App\Support\Authorization\CustomerLoginRateLimit;
use App\Support\Authorization\StaffLoginRateLimit;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantDatabaseContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            TenantDatabaseContext::class,
            fn () => new TenantDatabaseContext,
        );

        $this->app->singleton(
            TenantContext::class,
            fn ($app) => new TenantContext(
                $app->make(
                    TenantDatabaseContext::class
                ),
            ),
        );

        $this->app->singleton(
            AppInstanceToken::class,
            fn () => new AppInstanceToken(
                (string) config(
                    'platform.app_instance_credential_pepper'
                ),
            ),
        );
    }

    public function boot(): void
    {
        RateLimiter::for(
            StaffLoginRateLimit::NAME,
            fn (Request $request): array => StaffLoginRateLimit::limits(
                $request,
                app(TenantContext::class),
            ),
        );

        RateLimiter::for(
            CustomerLoginRateLimit::NAME,
            fn (Request $request): array => CustomerLoginRateLimit::limits(
                $request,
                app(TenantContext::class),
            ),
        );

        RateLimiter::for(
            ControlPlaneRateLimit::NAME,
            fn (Request $request): array => ControlPlaneRateLimit::limits(
                $request,
            ),
        );
    }
}
