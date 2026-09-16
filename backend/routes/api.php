<?php

use App\Http\Controllers\Api\V1\BootstrapController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Staff\AuthController;
use App\Support\Authorization\StaffLoginRateLimit;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', HealthController::class);

    Route::middleware([
        'app.instance',
        'tenant.boundary',
    ])->group(function () {
        Route::post(
            '/bootstrap',
            BootstrapController::class,
        );

        Route::get(
            '/branches',
            [BranchController::class, 'index'],
        );

        Route::prefix('staff/auth')->group(function () {
            Route::post(
                '/login',
                [AuthController::class, 'login'],
            )->middleware(
                'throttle:'.StaffLoginRateLimit::NAME
            );

            Route::middleware('tenant.staff')
                ->group(function () {
                    Route::get(
                        '/me',
                        [AuthController::class, 'me'],
                    );

                    Route::post(
                        '/logout',
                        [AuthController::class, 'logout'],
                    );
                });
        });
    });
});
