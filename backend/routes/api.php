<?php

use App\Http\Controllers\Api\V1\Admin\AuthorizationAdminController;
use App\Http\Controllers\Api\V1\Admin\CatalogAdminController;
use App\Http\Controllers\Api\V1\Admin\StaffAdminController;
use App\Http\Controllers\Api\V1\BootstrapController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Staff\AuthController;
use App\Http\Controllers\Api\V1\StorefrontCartController;
use App\Http\Controllers\Api\V1\StorefrontCatalogController;
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

        Route::get(
            '/storefront/home',
            [StorefrontCatalogController::class, 'home'],
        );

        Route::get(
            '/storefront/products',
            [StorefrontCatalogController::class, 'index'],
        );

        Route::post(
            '/storefront/carts',
            [StorefrontCartController::class, 'store'],
        );

        Route::get(
            '/storefront/carts/{cartPublicId}',
            [StorefrontCartController::class, 'show'],
        );

        Route::post(
            '/storefront/carts/{cartPublicId}/checkout/quote',
            [StorefrontCartController::class, 'quoteCheckout'],
        );

        Route::post(
            '/storefront/carts/{cartPublicId}/checkout/order',
            [StorefrontCartController::class, 'createCheckoutOrder'],
        );

        Route::post(
            '/storefront/carts/{cartPublicId}/items',
            [StorefrontCartController::class, 'storeItem'],
        );

        Route::patch(
            '/storefront/carts/{cartPublicId}/items/{itemPublicId}',
            [StorefrontCartController::class, 'updateItem'],
        );

        Route::delete(
            '/storefront/carts/{cartPublicId}/items/{itemPublicId}',
            [StorefrontCartController::class, 'destroyItem'],
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

                Route::get(
                    '/catalog/products',
                    [
                        CatalogAdminController::class,
                        'index',
                    ],
                )->middleware(
                    'permission:'.
                    PermissionCatalog::CATALOG_VIEW
                );

                Route::post(
                    '/catalog/products',
                    [
                        CatalogAdminController::class,
                        'storeProduct',
                    ],
                )->middleware(
                    'permission:'.
                    PermissionCatalog::CATALOG_MANAGE
                );

                Route::patch(
                    '/catalog/products/{productId}',
                    [
                        CatalogAdminController::class,
                        'updateProduct',
                    ],
                )->middleware(
                    'permission:'.
                    PermissionCatalog::CATALOG_MANAGE
                );

                Route::post(
                    '/catalog/products/{productId}/skus',
                    [
                        CatalogAdminController::class,
                        'storeSku',
                    ],
                )->middleware(
                    'permission:'.
                    PermissionCatalog::CATALOG_MANAGE
                );

                Route::patch(
                    '/catalog/skus/{skuId}',
                    [
                        CatalogAdminController::class,
                        'updateSku',
                    ],
                )->middleware(
                    'permission:'.
                    PermissionCatalog::CATALOG_MANAGE
                );
            });
    });
});
