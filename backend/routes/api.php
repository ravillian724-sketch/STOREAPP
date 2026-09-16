<?php

use App\Http\Controllers\Api\V1\BootstrapController;
use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', HealthController::class);

    Route::post('/bootstrap', BootstrapController::class)
        ->middleware([
            'app.instance',
            'tenant.boundary',
        ]);
});
