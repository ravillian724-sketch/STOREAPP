<?php

use App\Http\Controllers\Api\V1\BootstrapController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', HealthController::class);

    Route::middleware([
        'app.instance',
        'tenant.boundary',
    ])->group(function () {
        Route::post('/bootstrap', BootstrapController::class);
        Route::get('/branches', [BranchController::class, 'index']);
    });
});
