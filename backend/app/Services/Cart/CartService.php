<?php

namespace App\Services\Cart;

use App\Exceptions\Cart\CartNotAccessibleException;
use App\Exceptions\Cart\CartNotMutableException;
use App\Models\AppInstance;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\InventoryLocation;
use App\Models\Sku;
use App\Services\Inventory\InventoryReservationService;
use App\Support\Cart\CartStatus;
use App\Support\Cart\CreatedCart;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class CartService
{
    private const DEFAULT_TTL_DAYS = 30;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly InventoryReservationService $reservations,
    ) {}

    public function create(
        AppInstance $appInstance,
        ?DateTimeInterface $expiresAt = null,
    ): CreatedCart {
        $tenantId =
            $this->tenantContext->requireId();

        if (
            (int) $appInstance->tenant_id !==
                $tenantId
        ) {
            throw new LogicException(
                'App instance must belong to the active tenant.'
            );
        }

        if (! $appInstance->is_active) {
            throw new LogicException(
                'Cannot create a cart for an inactive app instance.'
            );
        }

        $normalizedExpiresAt =
            $expiresAt === null
                ? CarbonImmutable::now()
                    ->addDays(
                        self::DEFAULT_TTL_DAYS
                    )
                : CarbonImmutable::instance(
                    $expiresAt
                );

        if (
            $normalizedExpiresAt->getTimestamp()
            <= now()->getTimestamp()
        ) {
            throw new InvalidArgumentException(
                'Cart expiration must be in the future.'
            );
        }

        $rawToken =
            bin2hex(
                random_bytes(32)
            );

        $tokenHash =
            $this->hashToken(
                $rawToken
            );

        $cart = DB::transaction(
            function () use (
                $appInstance,
                $normalizedExpiresAt,
                $tokenHash,
            ): Cart {
                /*
                 * Re-read inside the transaction.
                 * Do not trust a potentially stale model.
                 */
                $freshInstance =
                    AppInstance::query()
                        ->whereKey(
                            $appInstance->id
                        )
                        ->lockForUpdate()
                        ->first();

                if (
                    $freshInstance === null ||
                    (int) $freshInstance->tenant_id !==
                        $this->tenantContext->requireId() ||
                    ! $freshInstance->is_active
                ) {
                    throw new LogicException(
                        'App instance is not available.'
                    );
                }

                return Cart::query()->create([
                    'app_instance_id' => $freshInstance->id,

                    'public_id' => (string) Str::uuid(),

                    'token_hash' => $tokenHash,

                    'status' => CartStatus::ACTIVE,

                    'expires_at' => $normalizedExpiresAt,
                ]);
            }
        );

        return new CreatedCart(
            cart: $cart,
            token: $rawToken,
        );
    }

    public function resolve(
        string $publicId,
        string $token,
    ): Cart {
        $this->tenantContext->requireId();

        $publicId = trim(
            $publicId
        );

        $token = trim(
            $token
        );

        if (
            $publicId === '' ||
            $token === ''
        ) {
            throw new CartNotAccessibleException;
        }

        $cart = Cart::query()
            ->where(
                'public_id',
                $publicId,
            )
            ->first();

        if (
            $cart === null ||
            ! hash_equals(
                $cart->token_hash,
                $this->hashToken(
                    $token
                ),
            )
        ) {
            /*
             * Same exception for unknown ID and
             * wrong token prevents cart enumeration.
             */
            throw new CartNotAccessibleException;
        }

        return $cart;
    }

    public function addItem(
        Cart $cart,
        Sku $sku,
        InventoryLocation $location,
        int $quantity,
    ): CartItem {
        if ($quantity <= 0) {
            throw new InvalidArgumentException(
                'Cart item quantity must be positive.'
            );
        }

        return DB::transaction(
            function () use (
                $cart,
                $sku,
                $location,
                $quantity,
            ): CartItem {
                $lockedCart =
                    $this->lockMutableCart(
                        $cart
                    );

                [
                    $freshSku,
                    $freshLocation,
                ] = $this->resolveInventory(
                    $sku,
                    $location,
                );

                $existing =
                    CartItem::query()
                        ->where(
                            'cart_id',
                            $lockedCart->id,
                        )
                        ->where(
                            'sku_id',
                            $freshSku->id,
                        )
                        ->where(
                            'location_id',
                            $freshLocation->id,
                        )
                        ->lockForUpdate()
                        ->first();

                if ($existing !== null) {
                    if (
                        $existing->quantity >
                        PHP_INT_MAX - $quantity
                    ) {
                        throw new InvalidArgumentException(
                            'Cart item quantity is too large.'
                        );
                    }

                    $existing->quantity =
                        $existing->quantity +
                        $quantity;

                    $existing->save();

                    $this->synchronizeReservationIfActive(
                        $lockedCart,
                        $existing,
                        $freshSku,
                        $freshLocation,
                    );

                    return $existing->refresh();
                }

                $item = CartItem::query()->create([
                    'cart_id' => $lockedCart->id,

                    'sku_id' => $freshSku->id,

                    'location_id' => $freshLocation->id,

                    'public_id' => (string) Str::uuid(),

                    'quantity' => $quantity,
                ]);

                $this->synchronizeReservationIfActive(
                    $lockedCart,
                    $item,
                    $freshSku,
                    $freshLocation,
                );

                return $item;
            }
        );
    }

    public function setQuantity(
        Cart $cart,
        CartItem $item,
        int $quantity,
    ): CartItem {
        if ($quantity <= 0) {
            throw new InvalidArgumentException(
                'Cart item quantity must be positive.'
            );
        }

        return DB::transaction(
            function () use (
                $cart,
                $item,
                $quantity,
            ): CartItem {
                $lockedCart =
                    $this->lockMutableCart(
                        $cart
                    );

                $lockedItem =
                    CartItem::query()
                        ->whereKey(
                            $item->id
                        )
                        ->where(
                            'cart_id',
                            $lockedCart->id,
                        )
                        ->lockForUpdate()
                        ->first();

                if ($lockedItem === null) {
                    throw new LogicException(
                        'Cart item does not belong to the cart.'
                    );
                }

                $lockedItem->quantity =
                    $quantity;

                $lockedItem->save();

                $sku =
                    Sku::query()
                        ->whereKey(
                            $lockedItem->sku_id
                        )
                        ->first();

                $location =
                    InventoryLocation::query()
                        ->whereKey(
                            $lockedItem->location_id
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

                $this->synchronizeReservationIfActive(
                    $lockedCart,
                    $lockedItem,
                    $sku,
                    $location,
                );

                return $lockedItem->refresh();
            }
        );
    }

    public function removeItem(
        Cart $cart,
        CartItem $item,
    ): void {
        DB::transaction(
            function () use (
                $cart,
                $item,
            ): void {
                $lockedCart =
                    $this->lockMutableCart(
                        $cart
                    );

                $lockedItem =
                    CartItem::query()
                        ->whereKey(
                            $item->id
                        )
                        ->where(
                            'cart_id',
                            $lockedCart->id,
                        )
                        ->lockForUpdate()
                        ->first();

                if ($lockedItem === null) {
                    throw new LogicException(
                        'Cart item does not belong to the cart.'
                    );
                }

                $this->releaseReservationIfActive(
                    $lockedCart,
                    $lockedItem,
                );

                $lockedItem->delete();
            }
        );
    }

    private function lockMutableCart(
        Cart $cart,
    ): Cart {
        $this->tenantContext->requireId();

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

    private function resolveInventory(
        Sku $sku,
        InventoryLocation $location,
    ): array {
        $tenantId =
            $this->tenantContext->requireId();

        if (
            (int) $sku->tenant_id !==
                $tenantId ||
            (int) $location->tenant_id !==
                $tenantId
        ) {
            throw new LogicException(
                'Cart inventory references must belong to the active tenant.'
            );
        }

        $freshSku =
            Sku::query()
                ->whereKey(
                    $sku->id
                )
                ->first();

        $freshLocation =
            InventoryLocation::query()
                ->whereKey(
                    $location->id
                )
                ->first();

        if (
            $freshSku === null ||
            $freshLocation === null
        ) {
            throw new LogicException(
                'Cart inventory reference is unavailable.'
            );
        }

        if (
            ! $freshSku->is_active ||
            ! $freshSku->track_inventory
        ) {
            throw new LogicException(
                'SKU is not available for inventory-backed cart operations.'
            );
        }

        if (! $freshLocation->is_active) {
            throw new LogicException(
                'Inventory location is inactive.'
            );
        }

        return [
            $freshSku,
            $freshLocation,
        ];
    }

    private function synchronizeReservationIfActive(
        Cart $cart,
        CartItem $item,
        Sku $sku,
        InventoryLocation $location,
    ): void {
        if (
            $cart->inventory_reserved_until === null ||
            ! $cart->inventory_reserved_until->isFuture()
        ) {
            return;
        }

        $this->reservations
            ->synchronizeReference(
                $sku,
                $location,
                (int) $item->quantity,
                'cart_item',
                $item->public_id,
                $cart->inventory_reserved_until,
            );
    }

    private function releaseReservationIfActive(
        Cart $cart,
        CartItem $item,
    ): void {
        if (
            $cart->inventory_reserved_until === null ||
            ! $cart->inventory_reserved_until->isFuture()
        ) {
            return;
        }

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

        $this->reservations
            ->releaseReference(
                $sku,
                $location,
                'cart_item',
                $item->public_id,
            );
    }

    private function hashToken(
        string $token,
    ): string {
        return hash(
            'sha256',
            $token,
        );
    }
}
