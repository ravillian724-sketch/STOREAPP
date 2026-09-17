<?php

namespace App\Services\Cart;

use App\Exceptions\Cart\CartNotAccessibleException;
use App\Exceptions\Cart\CartNotMutableException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\InventoryLocation;
use App\Models\Sku;
use App\Services\Inventory\InventoryReservationService;
use App\Support\Cart\CartStatus;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

final class CartCheckoutReservationService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly InventoryReservationService $reservations,
    ) {}

    public function begin(
        Cart $cart,
        DateTimeInterface $requestedExpiresAt,
    ): Cart {
        $this->tenantContext->requireId();

        $requestedExpiresAt =
            CarbonImmutable::instance(
                $requestedExpiresAt
            );

        /*
         * Every checkout request must carry a valid
         * application-selected future deadline.
         *
         * This remains true even when begin() is a replay
         * of an already active reservation window.
         */
        if (! $requestedExpiresAt->isFuture()) {
            throw new InvalidArgumentException(
                'Checkout reservation expiration must be in the future.'
            );
        }

        return DB::transaction(
            function () use (
                $cart,
                $requestedExpiresAt,
            ): Cart {
                $lockedCart =
                    $this->lockMutableCart(
                        $cart
                    );

                /*
                 * An already active reservation window is
                 * authoritative for retries.
                 *
                 * The caller may supply a later policy
                 * deadline on replay, but a replay must
                 * never extend an existing stock hold.
                 */
                if (
                    $lockedCart->inventory_reserved_until !== null &&
                    $lockedCart->inventory_reserved_until->isFuture()
                ) {
                    $candidateExpiresAt =
                        $lockedCart
                            ->inventory_reserved_until;
                } else {
                    /*
                     * Checkout policy belongs to the
                     * application layer. The domain receives
                     * an already validated absolute deadline.
                     */
                    $candidateExpiresAt =
                        $requestedExpiresAt;
                }

                /*
                 * Inventory must never remain reserved
                 * beyond the lifetime of the cart itself.
                 */
                $expiresAt =
                    $lockedCart->expires_at !== null &&
                    $candidateExpiresAt->greaterThan(
                        $lockedCart->expires_at
                    )
                        ? $lockedCart->expires_at
                        : $candidateExpiresAt;

                if (! $expiresAt->isFuture()) {
                    throw new CartNotMutableException;
                }

                /*
                 * Stable ordering is deliberate.
                 * Every checkout locks cart first,
                 * then items, then inventory positions.
                 */
                $items =
                    CartItem::query()
                        ->where(
                            'cart_id',
                            $lockedCart->id,
                        )
                        ->orderBy(
                            'location_id'
                        )
                        ->orderBy(
                            'sku_id'
                        )
                        ->orderBy(
                            'id'
                        )
                        ->lockForUpdate()
                        ->get();

                if ($items->isEmpty()) {
                    throw new LogicException(
                        'Cannot reserve inventory for an empty cart.'
                    );
                }

                foreach ($items as $item) {
                    [
                        $sku,
                        $location,
                    ] = $this->inventoryForItem(
                        $item
                    );

                    $this->reservations
                        ->synchronizeReference(
                            $sku,
                            $location,
                            (int) $item->quantity,
                            'cart_item',
                            $item->public_id,
                            $expiresAt,
                        );
                }

                $lockedCart
                    ->inventory_reserved_until =
                        $expiresAt;

                $lockedCart->save();

                return $lockedCart->refresh();
            }
        );
    }

    public function release(
        Cart $cart,
    ): Cart {
        $this->tenantContext->requireId();

        return DB::transaction(
            function () use ($cart): Cart {
                $lockedCart =
                    Cart::query()
                        ->whereKey(
                            $cart->id
                        )
                        ->lockForUpdate()
                        ->first();

                if ($lockedCart === null) {
                    throw new CartNotAccessibleException;
                }

                $items =
                    CartItem::query()
                        ->where(
                            'cart_id',
                            $lockedCart->id,
                        )
                        ->orderBy(
                            'location_id'
                        )
                        ->orderBy(
                            'sku_id'
                        )
                        ->orderBy(
                            'id'
                        )
                        ->lockForUpdate()
                        ->get();

                foreach ($items as $item) {
                    [
                        $sku,
                        $location,
                    ] = $this->inventoryReferencesForItem(
                        $item
                    );

                    $this->reservations
                        ->releaseReference(
                            $sku,
                            $location,
                            'cart_item',
                            $item->public_id,
                        );
                }

                $lockedCart
                    ->inventory_reserved_until =
                        null;

                $lockedCart->save();

                return $lockedCart->refresh();
            }
        );
    }

    private function lockMutableCart(
        Cart $cart,
    ): Cart {
        $lockedCart =
            Cart::query()
                ->whereKey(
                    $cart->id
                )
                ->lockForUpdate()
                ->first();

        if ($lockedCart === null) {
            throw new CartNotAccessibleException;
        }

        if (
            $lockedCart->status !==
                CartStatus::ACTIVE ||
            (
                $lockedCart->expires_at !== null &&
                ! $lockedCart->expires_at->isFuture()
            )
        ) {
            throw new CartNotMutableException;
        }

        return $lockedCart;
    }

    /**
     * Release operations need identity, not sellability.
     *
     * A SKU/location may be disabled after checkout started,
     * but its existing reservation must still be releasable.
     *
     * @return array{Sku, InventoryLocation}
     */
    private function inventoryReferencesForItem(
        CartItem $item,
    ): array {
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
            $location === null
        ) {
            throw new LogicException(
                'Cart inventory reference is unavailable.'
            );
        }

        return [
            $sku,
            $location,
        ];
    }

    /**
     * @return array{Sku, InventoryLocation}
     */
    private function inventoryForItem(
        CartItem $item,
    ): array {
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
            $location === null
        ) {
            throw new LogicException(
                'Cart inventory reference is unavailable.'
            );
        }

        if (
            ! $sku->is_active ||
            ! $sku->track_inventory ||
            ! $location->is_active
        ) {
            throw new LogicException(
                'Cart inventory is not reservable.'
            );
        }

        return [
            $sku,
            $location,
        ];
    }
}
