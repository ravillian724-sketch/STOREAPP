<?php

namespace App\Services\Storefront;

use App\Exceptions\Cart\CartItemNotFoundException;
use App\Exceptions\Cart\CartNotAccessibleException;
use App\Exceptions\Cart\CartSkuUnavailableException;
use App\Models\AppInstance;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartMutationReceipt;
use App\Models\InventoryLocation;
use App\Models\Sku;
use App\Services\Cart\CartService;
use InvalidArgumentException;
use RuntimeException;

final class StorefrontCartService
{
    public function __construct(
        private readonly CartService $carts,
        private readonly StorefrontCartCreationService $creation,
        private readonly StorefrontCartInventoryService $inventory,
        private readonly StorefrontCartViewService $view,
    ) {}

    /**
     * @return array{
     *   cart_token:string,
     *   data:array<string,mixed>
     * }
     */
    public function create(
        AppInstance $appInstance,
        Branch $branch,
        string $idempotencyKey,
    ): array {
        $created =
            $this->creation->create(
                $appInstance,
                $idempotencyKey,
            );

        return [
            'cart_token' => $created->token,

            'data' => $this->view->present(
                $created->cart,
                $branch,
            ),
        ];
    }

    public function resolve(
        AppInstance $appInstance,
        Branch $branch,
        string $cartPublicId,
        string $cartToken,
    ): Cart {
        $cart =
            $this->carts->resolve(
                $cartPublicId,
                $cartToken,
            );

        if (
            (int) $cart->app_instance_id !==
            (int) $appInstance->id
        ) {
            throw new CartNotAccessibleException;
        }

        $this->inventory
            ->assertCartBranch(
                $cart,
                $branch,
            );

        return $cart;
    }

    /**
     * @return array<string,mixed>
     */
    public function present(
        Cart $cart,
        Branch $branch,
    ): array {
        return $this->view
            ->present(
                $cart,
                $branch,
            );
    }

    /**
     * @return array<string,mixed>
     */
    public function addItem(
        Cart $cart,
        Branch $branch,
        int $skuId,
        int $quantity,
        string $idempotencyKey,
    ): array {
        $this->validateLineQuantity(
            $quantity
        );

        $recordedMutation =
            CartMutationReceipt::query()
                ->where(
                    'cart_id',
                    $cart->id,
                )
                ->where(
                    'idempotency_key',
                    $idempotencyKey,
                )
                ->first();

        if ($recordedMutation !== null) {
            $sku =
                Sku::query()
                    ->whereKey(
                        $skuId
                    )
                    ->first();

            if ($sku === null) {
                throw new CartSkuUnavailableException;
            }

            $resultItemPublicId =
                $recordedMutation
                    ->result_item_public_id;

            if ($resultItemPublicId === null) {
                throw new RuntimeException(
                    'Recorded cart mutation result is unavailable.'
                );
            }

            $recordedItem =
                CartItem::query()
                    ->where(
                        'cart_id',
                        $cart->id,
                    )
                    ->where(
                        'public_id',
                        $resultItemPublicId,
                    )
                    ->first();

            if ($recordedItem === null) {
                throw new RuntimeException(
                    'Recorded cart mutation result no longer exists.'
                );
            }

            $location =
                InventoryLocation::query()
                    ->whereKey(
                        $recordedItem
                            ->location_id
                    )
                    ->first();

            if ($location === null) {
                throw new RuntimeException(
                    'Recorded cart inventory location is unavailable.'
                );
            }

            $this->carts->addItem(
                $cart,
                $sku,
                $location,
                $quantity,
                $idempotencyKey,
            );

            return $this->view
                ->present(
                    $cart,
                    $branch,
                );
        }

        $sku =
            $this->inventory
                ->resolveSellableSku(
                    $skuId
                );

        if ($sku === null) {
            throw new CartSkuUnavailableException;
        }

        $existing =
            CartItem::query()
                ->where(
                    'cart_id',
                    $cart->id,
                )
                ->where(
                    'sku_id',
                    $sku->id,
                )
                ->get();

        if ($existing->count() > 1) {
            throw new RuntimeException(
                'Storefront cart contains duplicate SKU lines.'
            );
        }

        /** @var CartItem|null $existingItem */
        $existingItem =
            $existing->first();

        $currentQuantity =
            $existingItem === null
                ? 0
                : (int)
                    $existingItem
                        ->quantity;

        if (
            $currentQuantity >
            PHP_INT_MAX - $quantity
        ) {
            throw new InvalidArgumentException(
                'Cart quantity is too large.'
            );
        }

        $targetQuantity =
            $currentQuantity +
            $quantity;

        $this->validateLineQuantity(
            $targetQuantity
        );

        $location =
            $this->inventory
                ->locationForAddition(
                    $cart,
                    $branch,
                    $sku,
                    $quantity,
                );

        $this->carts->addItem(
            $cart,
            $sku,
            $location,
            $quantity,
            $idempotencyKey,
        );

        return $this->view
            ->present(
                $cart,
                $branch,
            );
    }

    /**
     * @return array<string,mixed>
     */
    public function setQuantity(
        Cart $cart,
        Branch $branch,
        string $itemPublicId,
        int $quantity,
    ): array {
        $this->validateLineQuantity(
            $quantity
        );

        $item =
            $this->inventory->item(
                $cart,
                $itemPublicId,
            );

        $this->inventory
            ->assertQuantityAvailable(
                $cart,
                $branch,
                $item,
                $quantity,
            );

        $this->carts->setQuantity(
            $cart,
            $item,
            $quantity,
        );

        return $this->view
            ->present(
                $cart,
                $branch,
            );
    }

    /**
     * DELETE is intentionally idempotent.
     *
     * A retry after a lost successful response must
     * not turn into an error merely because the item
     * is already absent.
     *
     * @return array<string,mixed>
     */
    public function removeItem(
        Cart $cart,
        Branch $branch,
        string $itemPublicId,
    ): array {
        try {
            $item =
                $this->inventory->item(
                    $cart,
                    $itemPublicId,
                );
        } catch (
            CartItemNotFoundException
        ) {
            return $this->view
                ->present(
                    $cart,
                    $branch,
                );
        }

        $this->carts->removeItem(
            $cart,
            $item,
        );

        return $this->view
            ->present(
                $cart,
                $branch,
            );
    }

    private function validateLineQuantity(
        int $quantity,
    ): void {
        $maximum = (int) config(
            'platform.storefront_cart_max_line_quantity',
            999,
        );

        if (
            $maximum < 1 ||
            $maximum > 1000000
        ) {
            throw new RuntimeException(
                'Storefront maximum line quantity is invalid.'
            );
        }

        if (
            $quantity < 1 ||
            $quantity > $maximum
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Cart line quantity must be between 1 and %d.',
                    $maximum,
                )
            );
        }
    }
}
