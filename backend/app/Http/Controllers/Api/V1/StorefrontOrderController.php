<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Order\OrderNotAccessibleException;
use App\Http\Controllers\Controller;
use App\Models\AppInstance;
use App\Services\Storefront\StorefrontOrderService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;

class StorefrontOrderController extends Controller
{
    public function __construct(
        private readonly StorefrontOrderService $orders,
    ) {}

    public function show(
        Request $request,
        string $orderPublicId,
    ): JsonResponse {
        $token = trim(
            (string)
            $request->header(
                'X-Order-Token',
                '',
            )
        );
        if ($token === '') {
            return ApiResponse::error(
                $request,
                'ORDER_TOKEN_REQUIRED',
                'Order token is required.',
                400,
            );
        }

        try {
            $order =
                $this->orders->resolveGuest(
                    $this->appInstance($request),
                    $orderPublicId,
                    $token,
                );

            $response = ApiResponse::success(
                $request,
                $this->orders->present($order),
            );

            $response->headers->set(
                'Cache-Control',
                'private, no-store',
            );

            return $response;
        } catch (OrderNotAccessibleException) {
            return ApiResponse::error(
                $request,
                'ORDER_NOT_FOUND',
                'Order was not found.',
                404,
            );
        }
    }

    private function appInstance(
        Request $request,
    ): AppInstance {
        $instance =
            $request->attributes->get(
                'app_instance'
            );

        if (! $instance instanceof AppInstance) {
            throw new LogicException(
                'App instance middleware context is unavailable.'
            );
        }

        return $instance;
    }
}
