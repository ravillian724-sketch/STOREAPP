<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Storefront\StorefrontOrderService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;

class CustomerOrderController extends Controller
{
    public function __construct(
        private readonly StorefrontOrderService $orders,
    ) {}

    public function index(
        Request $request,
    ): JsonResponse {
        $validated = $request->validate([
            'page' => [
                'sometimes',
                'integer',
                'min:1',
            ],
            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:50',
            ],
        ]);

        $customer =
            $this->customer(
                $request
            );

        $page =
            (int) ($validated['page'] ?? 1);

        $perPage =
            (int) ($validated['per_page'] ?? 20);

        $paginator =
            Order::query()
                ->with([
                    'items',
                    'payment',
                ])
                ->where(
                    'customer_id',
                    $customer->id,
                )
                ->orderByDesc(
                    'created_at'
                )
                ->orderByDesc(
                    'id'
                )
                ->paginate(
                    $perPage,
                    ['*'],
                    'page',
                    $page,
                );

        $payload = [
            'orders' => collect(
                $paginator->items()
            )
                ->map(
                    fn (Order $order): array => $this->orders
                        ->present(
                            $order
                        )
                )
                ->values()
                ->all(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];

        $response =
            ApiResponse::success(
                $request,
                $payload,
            );

        $response->headers->set(
            'Cache-Control',
            'private, no-store',
        );

        return $response;
    }

    private function customer(
        Request $request,
    ): Customer {
        $customer =
            $request->attributes->get(
                'storefront_customer'
            );

        if (! $customer instanceof Customer) {
            throw new LogicException(
                'Customer middleware context is unavailable.'
            );
        }

        return $customer;
    }
}
