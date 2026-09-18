<?php

namespace App\Services\Storefront;

use App\Exceptions\Cart\CartBranchMismatchException;
use App\Exceptions\Cart\CartItemNotFoundException;
use App\Exceptions\Inventory\InsufficientAvailableStockException;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\InventoryLocation;
use App\Models\InventoryReservation;
use App\Models\Sku;
use App\Services\Inventory\InventoryAvailabilityService;
use App\Support\Inventory\InventoryReservationStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use LogicException;
use OverflowException;

final class StorefrontCartInventoryService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly InventoryAvailabilityService $availability,
    ) {}

    public function resolveSellableSku(
        int $skuId,
    ): ?Sku {
        if ($skuId <= 0) {
            return null;
        }

        return Sku::query()
            ->with('product')
            ->whereKey($skuId)
            ->where('is_active', true)
            ->where('track_inventory', true)
            ->whereHas(
                'product',
                fn ($query) => $query->where(
                    'is_active',
                    true,
                ),
            )
            ->first();
    }

    public function assertCartBranch(
        Cart $cart,
        Branch $branch,
    ): void {
        $tenantId =
            $this->tenantContext->requireId();

        if (
            (int) $cart->tenant_id !==
                $tenantId ||
            (int) $branch->tenant_id !==
                $tenantId
        ) {
            throw new LogicException(
                'Cart and branch must belong to the active tenant.'
            );
        }

        $hasDifferentBranch =
            CartItem::query()
                ->where(
                    'cart_id',
                    $cart->id,
                )
                ->whereHas(
                    'location',
                    fn ($query) => $query->where(
                        'branch_id',
                        '!=',
                        $branch->id,
                    ),
                )
                ->exists();

        if ($hasDifferentBranch) {
            throw new CartBranchMismatchException;
        }
    }

    public function item(
        Cart $cart,
        string $itemPublicId,
    ): CartItem {
        $itemPublicId = trim(
            $itemPublicId
        );

        if ($itemPublicId === '') {
            throw new CartItemNotFoundException;
        }

        $item =
            CartItem::query()
                ->where(
                    'cart_id',
                    $cart->id,
                )
                ->where(
                    'public_id',
                    $itemPublicId,
                )
                ->first();

        if ($item === null) {
            throw new CartItemNotFoundException;
        }

        return $item;
    }

    public function locationForAddition(
        Cart $cart,
        Branch $branch,
        Sku $sku,
        int $additionalQuantity,
    ): InventoryLocation {
        if ($additionalQuantity <= 0) {
            throw new InvalidArgumentException(
                'Additional cart quantity must be positive.'
            );
        }

        $this->assertCartBranch(
            $cart,
            $branch,
        );

        $existingItems =
            CartItem::query()
                ->where(
                    'cart_id',
                    $cart->id,
                )
                ->where(
                    'sku_id',
                    $sku->id,
                )
                ->orderBy('id')
                ->get();

        if ($existingItems->count() > 1) {
            throw new LogicException(
                'Storefront cart contains the same SKU in multiple inventory locations.'
            );
        }

        /** @var CartItem|null $existing */
        $existing =
            $existingItems->first();

        if ($existing !== null) {
            $location =
                InventoryLocation::query()
                    ->whereKey(
                        $existing->location_id
                    )
                    ->where(
                        'branch_id',
                        $branch->id,
                    )
                    ->where(
                        'type',
                        'stock',
                    )
                    ->where(
                        'is_active',
                        true,
                    )
                    ->first();

            if ($location === null) {
                throw new CartBranchMismatchException;
            }

            if (
                (int) $existing->quantity >
                PHP_INT_MAX - $additionalQuantity
            ) {
                throw new OverflowException(
                    'Requested cart quantity is too large.'
                );
            }

            $targetQuantity =
                (int) $existing->quantity +
                $additionalQuantity;

            $capacity =
                $this->capacityForItem(
                    $existing,
                    $sku,
                    $location,
                );

            if (
                $targetQuantity >
                $capacity
            ) {
                throw new InsufficientAvailableStockException(
                    requestedQuantity: $targetQuantity,
                    availableQuantity: $capacity,
                );
            }

            return $location;
        }

        return $this->bestLocation(
            $branch,
            $sku,
            $additionalQuantity,
        );
    }

    public function assertQuantityAvailable(
        Cart $cart,
        Branch $branch,
        CartItem $item,
        int $quantity,
    ): void {
        if ($quantity <= 0) {
            throw new InvalidArgumentException(
                'Cart item quantity must be positive.'
            );
        }

        $this->assertCartBranch(
            $cart,
            $branch,
        );

        $sku =
            Sku::query()
                ->whereKey(
                    $item->sku_id
                )
                ->where(
                    'is_active',
                    true,
                )
                ->where(
                    'track_inventory',
                    true,
                )
                ->first();

        $location =
            InventoryLocation::query()
                ->whereKey(
                    $item->location_id
                )
                ->where(
                    'branch_id',
                    $branch->id,
                )
                ->where(
                    'type',
                    'stock',
                )
                ->where(
                    'is_active',
                    true,
                )
                ->first();

        if (
            $sku === null ||
            $location === null
        ) {
            throw new LogicException(
                'Cart inventory is no longer sellable.'
            );
        }

        $capacity =
            $this->capacityForItem(
                $item,
                $sku,
                $location,
            );

        if (
            $quantity >
            $capacity
        ) {
            throw new InsufficientAvailableStockException(
                requestedQuantity: $quantity,
                availableQuantity: $capacity,
            );
        }
    }

    public function maxQuantity(
        CartItem $item,
    ): int {
        $sku =
            Sku::query()
                ->whereKey(
                    $item->sku_id
                )
                ->first();

        $location =
            InventoryLocation::query()
                ->whereKey(
                    $item->location_id
                )
                ->first();

        if (
            $sku === null ||
            $location === null ||
            ! $sku->is_active ||
            ! $sku->track_inventory ||
            ! $location->is_active
        ) {
            return 0;
        }

        return max(
            0,
            $this->capacityForItem(
                $item,
                $sku,
                $location,
            ),
        );
    }

    private function bestLocation(
        Branch $branch,
        Sku $sku,
        int $quantity,
    ): InventoryLocation {
        /** @var Collection<int, InventoryLocation> $locations */
        $locations =
            InventoryLocation::query()
                ->where(
                    'branch_id',
                    $branch->id,
                )
                ->where(
                    'type',
                    'stock',
                )
                ->where(
                    'is_active',
                    true,
                )
                ->orderBy('id')
                ->get();

        $best = null;
        $bestAvailable = -1;
        $highestAvailable = 0;

        foreach ($locations as $location) {
            $available =
                max(
                    0,
                    $this->availability
                        ->availableToSell(
                            $sku,
                            $location,
                        ),
                );

            $highestAvailable =
                max(
                    $highestAvailable,
                    $available,
                );

            if (
                $available >= $quantity &&
                $available > $bestAvailable
            ) {
                $best = $location;
                $bestAvailable = $available;
            }
        }

        if ($best === null) {
            throw new InsufficientAvailableStockException(
                requestedQuantity: $quantity,
                availableQuantity: $highestAvailable,
            );
        }

        return $best;
    }

    private function capacityForItem(
        CartItem $item,
        Sku $sku,
        InventoryLocation $location,
    ): int {
        $available =
            max(
                0,
                $this->availability
                    ->availableToSell(
                        $sku,
                        $location,
                    ),
            );

        $ownReservations =
            InventoryReservation::query()
                ->where(
                    'reference_type',
                    'cart_item',
                )
                ->where(
                    'reference_id',
                    $item->public_id,
                )
                ->where(
                    'status',
                    InventoryReservationStatus::ACTIVE,
                )
                ->get();

        if ($ownReservations->count() > 1) {
            throw new LogicException(
                'Multiple active reservations exist for the cart item.'
            );
        }

        /** @var InventoryReservation|null $reservation */
        $reservation =
            $ownReservations->first();

        if ($reservation === null) {
            return $available;
        }

        if (
            (int) $reservation->sku_id !==
                (int) $sku->id ||
            (int) $reservation->location_id !==
                (int) $location->id
        ) {
            throw new LogicException(
                'Cart reservation points to different inventory.'
            );
        }

        $isCounted =
            $reservation->expires_at === null ||
            $reservation->expires_at->isFuture();

        if (! $isCounted) {
            return $available;
        }

        if (
            (int) $reservation->quantity >
            PHP_INT_MAX - $available
        ) {
            throw new OverflowException(
                'Available inventory capacity overflow.'
            );
        }

        return $available +
            (int) $reservation->quantity;
    }
}
