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
use Illuminate\Support\Facades\DB;
use LogicException;

final class CartLifecycleService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly InventoryReservationService $reservations,
    ) {}

    public function abandon(
        Cart $cart,
    ): Cart {
        $this->tenantContext->requireId();

        return DB::transaction(
            function () use ($cart): Cart {
                $lockedCart =
                    $this->lockCart(
                        $cart
                    );

                if (
                    $lockedCart->status ===
                    CartStatus::ABANDONED
                ) {
                    return $lockedCart;
                }

                if (
                    $lockedCart->status !==
                    CartStatus::ACTIVE
                ) {
                    throw new CartNotMutableException;
                }

                /*
                 * Time expiry has precedence over a later
                 * abandonment request. Do not rewrite
                 * historical meaning.
                 */
                if ($this->isDue($lockedCart)) {
                    return $this->expireLocked(
                        $lockedCart
                    );
                }

                $this->releaseReservations(
                    $lockedCart
                );

                $lockedCart->status =
                    CartStatus::ABANDONED;

                $lockedCart->abandoned_at =
                    CarbonImmutable::now();

                $lockedCart->inventory_reserved_until =
                    null;

                $lockedCart->save();

                return $lockedCart->refresh();
            }
        );
    }

    public function expireIfDue(
        Cart $cart,
    ): Cart {
        $this->tenantContext->requireId();

        return DB::transaction(
            function () use ($cart): Cart {
                $lockedCart =
                    $this->lockCart(
                        $cart
                    );

                if (
                    $lockedCart->status ===
                    CartStatus::EXPIRED
                ) {
                    return $lockedCart;
                }

                /*
                 * Terminal states never rewrite each other.
                 *
                 * Converted carts belong to the future
                 * Order transaction. Abandoned carts remain
                 * abandoned even if their expires_at later
                 * passes.
                 */
                if (
                    $lockedCart->status !==
                    CartStatus::ACTIVE
                ) {
                    return $lockedCart;
                }

                if (! $this->isDue($lockedCart)) {
                    return $lockedCart;
                }

                return $this->expireLocked(
                    $lockedCart
                );
            }
        );
    }

    private function lockCart(
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

        return $lockedCart;
    }

    private function isDue(
        Cart $cart,
    ): bool {
        return
            $cart->expires_at !== null &&
            ! $cart->expires_at->isFuture();
    }

    private function expireLocked(
        Cart $cart,
    ): Cart {
        $this->releaseReservations(
            $cart
        );

        $cart->status =
            CartStatus::EXPIRED;

        $cart->expired_at =
            CarbonImmutable::now();

        $cart->inventory_reserved_until =
            null;

        $cart->save();

        return $cart->refresh();
    }

    private function releaseReservations(
        Cart $cart,
    ): void {
        /*
         * Same global lock order used by Checkout:
         *
         * Cart
         * -> Cart Items
         * -> Inventory Positions
         *
         * This preserves the deadlock strategy already
         * proven by PostgreSQL concurrency tests.
         */
        $items =
            CartItem::query()
                ->where(
                    'cart_id',
                    $cart->id,
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
            ] = $this->inventoryReferences(
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
    }

    /**
     * Terminal cleanup needs inventory identity,
     * not current sellability.
     *
     * @return array{Sku, InventoryLocation}
     */
    private function inventoryReferences(
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
}
