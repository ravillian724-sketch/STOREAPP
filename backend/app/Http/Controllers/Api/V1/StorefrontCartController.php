<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Cart\CartBranchMismatchException;
use App\Exceptions\Cart\CartIdempotencyConflictException;
use App\Exceptions\Cart\CartItemNotFoundException;
use App\Exceptions\Cart\CartNotAccessibleException;
use App\Exceptions\Cart\CartNotMutableException;
use App\Exceptions\Cart\CartSkuUnavailableException;
use App\Exceptions\Inventory\InsufficientAvailableStockException;
use App\Http\Controllers\Controller;
use App\Models\AppInstance;
use App\Models\Branch;
use App\Models\Cart;
use App\Services\Storefront\StorefrontBranchResolver;
use App\Services\Storefront\StorefrontCartService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use LogicException;

class StorefrontCartController extends Controller
{
    public function __construct(
        private readonly StorefrontBranchResolver $branches,
        private readonly StorefrontCartService $carts,
    ) {}

    public function store(
        Request $request,
    ): JsonResponse {
        $branch =
            $this->branch($request);

        if ($branch === null) {
            return $this->branchNotFound(
                $request
            );
        }

        $key =
            $this->idempotencyKey(
                $request
            );

        if ($key === null) {
            return $this->idempotencyRequired(
                $request
            );
        }

        $instance =
            $this->appInstance(
                $request
            );

        try {
            $created =
                $this->carts->create(
                    $instance,
                    $branch,
                    $key,
                );

            return ApiResponse::success(
                $request,
                [
                    'cart_token' => $created[
                            'cart_token'
                        ],

                    ...$created['data'],
                ],
                201,
            );
        } catch (
            InvalidArgumentException
        ) {
            return $this->invalidCartRequest(
                $request
            );
        } catch (
            CartIdempotencyConflictException
        ) {
            return $this->idempotencyConflict(
                $request
            );
        }
    }

    public function show(
        Request $request,
        string $cartPublicId,
    ): JsonResponse {
        $resolved =
            $this->resolveCart(
                $request,
                $cartPublicId,
            );

        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        [
            $cart,
            $branch,
        ] = $resolved;

        try {
            return ApiResponse::success(
                $request,
                $this->carts->present(
                    $cart,
                    $branch,
                ),
            );
        } catch (LogicException) {
            return $this->reviewRequired(
                $request
            );
        }
    }

    public function storeItem(
        Request $request,
        string $cartPublicId,
    ): JsonResponse {
        $validated =
            $request->validate([
                'sku_id' => [
                    'required',
                    'integer',
                    'min:1',
                ],

                'quantity' => [
                    'required',
                    'integer',
                    'min:1',
                ],
            ]);

        $key =
            $this->idempotencyKey(
                $request
            );

        if ($key === null) {
            return $this->idempotencyRequired(
                $request
            );
        }

        $resolved =
            $this->resolveCart(
                $request,
                $cartPublicId,
            );

        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        [
            $cart,
            $branch,
        ] = $resolved;

        try {
            return ApiResponse::success(
                $request,
                $this->carts->addItem(
                    $cart,
                    $branch,
                    (int) $validated['sku_id'],
                    (int) $validated['quantity'],
                    $key,
                ),
            );
        } catch (
            CartSkuUnavailableException
        ) {
            return $this->skuNotFound(
                $request
            );
        } catch (
            InsufficientAvailableStockException
        ) {
            return $this->insufficientStock(
                $request
            );
        } catch (
            CartIdempotencyConflictException
        ) {
            return $this->idempotencyConflict(
                $request
            );
        } catch (
            CartNotMutableException
        ) {
            return $this->notMutable(
                $request
            );
        } catch (
            InvalidArgumentException
        ) {
            return $this->invalidCartRequest(
                $request
            );
        } catch (
            LogicException
        ) {
            return $this->reviewRequired(
                $request
            );
        }
    }

    public function updateItem(
        Request $request,
        string $cartPublicId,
        string $itemPublicId,
    ): JsonResponse {
        $validated =
            $request->validate([
                'quantity' => [
                    'required',
                    'integer',
                    'min:1',
                ],
            ]);

        $resolved =
            $this->resolveCart(
                $request,
                $cartPublicId,
            );

        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        [
            $cart,
            $branch,
        ] = $resolved;

        try {
            return ApiResponse::success(
                $request,
                $this->carts->setQuantity(
                    $cart,
                    $branch,
                    $itemPublicId,
                    (int) $validated['quantity'],
                ),
            );
        } catch (
            InsufficientAvailableStockException
        ) {
            return $this->insufficientStock(
                $request
            );
        } catch (
            CartItemNotFoundException
        ) {
            return $this->itemNotFound(
                $request
            );
        } catch (
            CartNotMutableException
        ) {
            return $this->notMutable(
                $request
            );
        } catch (
            InvalidArgumentException
        ) {
            return $this->invalidCartRequest(
                $request
            );
        } catch (
            LogicException
        ) {
            return $this->reviewRequired(
                $request
            );
        }
    }

    public function destroyItem(
        Request $request,
        string $cartPublicId,
        string $itemPublicId,
    ): JsonResponse {
        $resolved =
            $this->resolveCart(
                $request,
                $cartPublicId,
            );

        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        [
            $cart,
            $branch,
        ] = $resolved;

        try {
            return ApiResponse::success(
                $request,
                $this->carts->removeItem(
                    $cart,
                    $branch,
                    $itemPublicId,
                ),
            );
        } catch (
            CartNotMutableException
        ) {
            return $this->notMutable(
                $request
            );
        } catch (
            LogicException
        ) {
            return $this->reviewRequired(
                $request
            );
        }
    }

    /**
     * @return array{Cart, Branch}|JsonResponse
     */
    private function resolveCart(
        Request $request,
        string $cartPublicId,
    ): array|JsonResponse {
        $branch =
            $this->branch($request);

        if ($branch === null) {
            return $this->branchNotFound(
                $request
            );
        }

        $token =
            trim(
                (string)
                $request->header(
                    'X-Cart-Token',
                    '',
                )
            );

        if ($token === '') {
            return ApiResponse::error(
                $request,
                'CART_TOKEN_REQUIRED',
                'Cart token is required.',
                400,
            );
        }

        try {
            $cart =
                $this->carts->resolve(
                    $this->appInstance(
                        $request
                    ),
                    $branch,
                    $cartPublicId,
                    $token,
                );
        } catch (
            CartNotAccessibleException
        ) {
            return $this->cartNotFound(
                $request
            );
        } catch (
            CartBranchMismatchException
        ) {
            return ApiResponse::error(
                $request,
                'CART_BRANCH_MISMATCH',
                'Cart belongs to a different branch.',
                409,
            );
        }

        return [
            $cart,
            $branch,
        ];
    }

    private function branch(
        Request $request,
    ): ?Branch {
        return $this->branches->resolve(
            (string) $request->header(
                'X-Branch-Id',
                '',
            )
        );
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

    private function idempotencyKey(
        Request $request,
    ): ?string {
        $key =
            trim(
                (string)
                $request->header(
                    'Idempotency-Key',
                    '',
                )
            );

        return $key === ''
            ? null
            : $key;
    }

    private function branchNotFound(
        Request $request,
    ): JsonResponse {
        return ApiResponse::error(
            $request,
            'BRANCH_NOT_FOUND',
            'The selected branch is unavailable.',
            404,
        );
    }

    private function cartNotFound(
        Request $request,
    ): JsonResponse {
        return ApiResponse::error(
            $request,
            'CART_NOT_FOUND',
            'Cart was not found.',
            404,
        );
    }

    private function skuNotFound(
        Request $request,
    ): JsonResponse {
        return ApiResponse::error(
            $request,
            'SKU_NOT_FOUND',
            'Product is unavailable.',
            404,
        );
    }

    private function itemNotFound(
        Request $request,
    ): JsonResponse {
        return ApiResponse::error(
            $request,
            'CART_ITEM_NOT_FOUND',
            'Cart item was not found.',
            404,
        );
    }

    private function insufficientStock(
        Request $request,
    ): JsonResponse {
        return ApiResponse::error(
            $request,
            'INSUFFICIENT_STOCK',
            'Requested quantity is not currently available.',
            409,
        );
    }

    private function notMutable(
        Request $request,
    ): JsonResponse {
        return ApiResponse::error(
            $request,
            'CART_NOT_MUTABLE',
            'Cart cannot be modified.',
            409,
        );
    }

    private function reviewRequired(
        Request $request,
    ): JsonResponse {
        return ApiResponse::error(
            $request,
            'CART_REVIEW_REQUIRED',
            'Cart must be reviewed before continuing.',
            409,
        );
    }

    private function idempotencyRequired(
        Request $request,
    ): JsonResponse {
        return ApiResponse::error(
            $request,
            'IDEMPOTENCY_KEY_REQUIRED',
            'Idempotency key is required.',
            400,
        );
    }

    private function idempotencyConflict(
        Request $request,
    ): JsonResponse {
        return ApiResponse::error(
            $request,
            'IDEMPOTENCY_CONFLICT',
            'Idempotency key was already used for a different request.',
            409,
        );
    }

    private function invalidCartRequest(
        Request $request,
    ): JsonResponse {
        return ApiResponse::error(
            $request,
            'INVALID_CART_REQUEST',
            'Cart request is invalid.',
            422,
        );
    }
}
