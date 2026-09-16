<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HealthController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return ApiResponse::success($request, [
            'status' => 'ok',
            'service' => 'storeapp-api',
            'version' => 'v1',
        ]);
    }
}
