<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ApiResponse
{
    public static function success(
        Request $request,
        mixed $data = null,
        int $status = 200
    ): JsonResponse {
        return response()->json([
            'data' => $data,
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
            ],
        ], $status);
    }

    public static function error(
        Request $request,
        string $code,
        string $message,
        int $status
    ): JsonResponse {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
            ],
        ], $status);
    }
}
