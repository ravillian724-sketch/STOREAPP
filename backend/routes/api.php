<?php

use App\Http\Controllers\Api\V1\Admin\AuthorizationAdminController;
use App\Http\Controllers\Api\V1\Admin\StaffAdminController;
use App\Http\Controllers\Api\V1\BootstrapController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Staff\AuthController;
use App\Support\Authorization\PermissionCatalog;
use App\Support\Authorization\StaffLoginRateLimit;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get(
        '/health',
        HealthController::class,
    );

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

        Route::prefix('staff/auth')
            ->group(function () {
                Route::post(
                    '/login',
                    [AuthController::class, 'login'],
                )->middleware(
                    'throttle:'.
                    StaffLoginRateLimit::NAME
                );

                Route::middleware(
                    'tenant.staff'
                )->group(function () {
                    Route::get(
                        '/me',
                        [
                            AuthController::class,
                            'me',
                        ],
                    );

                    Route::post(
                        '/logout',
                        [
                            AuthController::class,
                            'logout',
                        ],
                    );
                });
            });

        Route::prefix('admin')
            ->middleware('tenant.staff')
            ->group(function () {
                Route::get(
                    '/staff',
                    [
                        StaffAdminController::class,
                        'index',
                    ],
                )->middleware(
                    'permission:'.
                    PermissionCatalog::STAFF_VIEW
                );

                Route::post(
                    '/staff',
                    [
                        StaffAdminController::class,
                        'store',
                    ],
                )->middleware(
                    'permission:'.
                    PermissionCatalog::STAFF_MANAGE
                );

                Route::patch(
                    '/staff/{staffId}',
                    [
                        StaffAdminController::class,
                        'update',
                    ],
                )->middleware(
                    'permission:'.
                    PermissionCatalog::STAFF_MANAGE
                );

                Route::get(
                    '/roles',
                    [
                        AuthorizationAdminController::class,
                        'roles',
                    ],
                )->middleware(
                    'permission:'.
                    PermissionCatalog::ROLES_VIEW
                );

                Route::get(
                    '/permissions',
                    [
                        AuthorizationAdminController::class,
                        'permissions',
                    ],
                )->middleware(
                    'permission:'.
                    PermissionCatalog::ROLES_VIEW
                );

                Route::post(
                    '/roles',
                    [
                        AuthorizationAdminController::class,
                        'storeRole',
                    ],
                )->middleware(
                    'permission:'.
                    PermissionCatalog::ROLES_MANAGE
                );

                Route::patch(
                    '/roles/{roleId}',
                    [
                        AuthorizationAdminController::class,
                        'updateRole',
                    ],
                )->middleware(
                    'permission:'.
                    PermissionCatalog::ROLES_MANAGE
                );
            });
    });
});
