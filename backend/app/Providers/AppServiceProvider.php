<?php

namespace App\Providers;

use App\Support\AppInstance\AppInstanceToken;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            TenantContext::class,
            fn () => new TenantContext,
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
        //
    }
}
