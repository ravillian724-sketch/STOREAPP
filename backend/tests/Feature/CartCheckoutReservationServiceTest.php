<?php

namespace Tests\Feature;

use App\Exceptions\Inventory\InsufficientAvailableStockException;
use App\Models\AppInstance;
use App\Models\Cart;
use App\Models\InventoryLocation;
use App\Models\InventoryReservation;
use App\Models\Product;
use App\Models\Sku;
use App\Models\Tenant;
use App\Services\Cart\CartCheckoutReservationService;
use App\Services\Cart\CartService;
use App\Services\Inventory\InventoryAvailabilityService;
use App\Services\Inventory\StockLedgerService;
use App\Support\Inventory\InventoryMovementType;
use App\Support\Inventory\InventoryReservationStatus;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class CartCheckoutReservationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Tenant
    {
        return Tenant::query()->create([
            'name_ar' => 'Tenant A',
            'name_en' => 'Tenant A',
            'country_code' => 'SA',
            'currency_code' => 'SAR',
            'vat_rate' => 15,
            'primary_color' => '#000000',
            'secondary_color' => '#FFFFFF',
            'is_active' => true,
        ]);
    }

    private function inTenant(
        Tenant $tenant,
        callable $callback,
    ): mixed {
        $context =
            app(TenantContext::class);

        $previous =
            $context->id();

        $context->set(
            $tenant->id
        );

        try {
            return $callback();
        } finally {
            if ($previous === null) {
                $context->clear();
            } else {
                $context->set(
                    $previous
                );
            }
        }
    }

    private function appInstance(
        Tenant $tenant,
    ): AppInstance {
        return AppInstance::query()->create([
            'tenant_id' => $tenant->id,
            'channel' => 'mobile',
            'is_active' => true,
        ]);
    }

    private function inventory(
        Tenant $tenant,
        string $code,
        int $opening,
    ): array {
        return $this->inTenant(
            $tenant,
            function () use (
                $code,
                $opening,
            ): array {
                $product =
                    Product::query()->create([
                        'name_ar' => $code,
                        'name_en' => $code,
                        'is_active' => true,
                    ]);

                $sku =
                    Sku::query()->create([
                        'product_id' => $product->id,
                        'code' => $code,
                        'track_inventory' => true,
                        'is_active' => true,
                    ]);

                $location =
                    InventoryLocation::query()
                        ->firstOrCreate(
                            [
                                'code' => 'MAIN',
                            ],
                            [
                                'branch_id' => null,
                                'name_ar' => 'MAIN',
                                'name_en' => 'MAIN',
                                'type' => 'stock',
                                'is_active' => true,
                            ],
                        );

                app(
                    StockLedgerService::class
                )->post(
                    $sku,
                    $location,
                    $opening,
                    InventoryMovementType::OPENING,
                    'checkout-opening-'.$code,
                );

                return [
                    $sku,
                    $location,
                ];
            },
        );
    }

    private function cart(
        Tenant $tenant,
    ): Cart {
        $instance =
            $this->appInstance(
                $tenant
            );

        return $this->inTenant(
            $tenant,
            fn (): Cart => app(CartService::class)
                ->create(
                    $instance
                )
                ->cart,
        );
    }

    public function test_begin_checkout_reserves_every_cart_item_atomically(): void
    {
        $tenant =
            $this->tenant();

        $cart =
            $this->cart($tenant);

        [
            $skuA,
            $location,
        ] = $this->inventory(
            $tenant,
            'SKU-A',
            10,
        );

        [
            $skuB,
        ] = $this->inventory(
            $tenant,
            'SKU-B',
            8,
        );

        $this->inTenant(
            $tenant,
            function () use (
                $cart,
                $skuA,
                $skuB,
                $location,
            ): void {
                $cartService =
                    app(CartService::class);

                $cartService->addItem(
                    $cart,
                    $skuA,
                    $location,
                    3,
                );

                $cartService->addItem(
                    $cart,
                    $skuB,
                    $location,
                    2,
                );

                $reservedCart =
                    app(
                        CartCheckoutReservationService::class
                    )->begin(
                        $cart
                    );

                $this->assertNotNull(
                    $reservedCart
                        ->inventory_reserved_until
                );

                $this->assertSame(
                    2,
                    InventoryReservation::query()
                        ->where(
                            'status',
                            InventoryReservationStatus::ACTIVE,
                        )
                        ->count(),
                );

                $availability =
                    app(
                        InventoryAvailabilityService::class
                    );

                $this->assertSame(
                    7,
                    $availability->availableToSell(
                        $skuA,
                        $location,
                    ),
                );

                $this->assertSame(
                    6,
                    $availability->availableToSell(
                        $skuB,
                        $location,
                    ),
                );
            },
        );
    }

    public function test_failed_checkout_reservation_rolls_back_all_items(): void
    {
        $tenant =
            $this->tenant();

        $cart =
            $this->cart($tenant);

        [
            $skuA,
            $location,
        ] = $this->inventory(
            $tenant,
            'SKU-A',
            10,
        );

        [
            $skuB,
        ] = $this->inventory(
            $tenant,
            'SKU-B',
            1,
        );

        $this->inTenant(
            $tenant,
            function () use (
                $cart,
                $skuA,
                $skuB,
                $location,
            ): void {
                $cartService =
                    app(CartService::class);

                $cartService->addItem(
                    $cart,
                    $skuA,
                    $location,
                    3,
                );

                $cartService->addItem(
                    $cart,
                    $skuB,
                    $location,
                    2,
                );

                try {
                    app(
                        CartCheckoutReservationService::class
                    )->begin(
                        $cart
                    );

                    $this->fail(
                        'Expected insufficient stock.'
                    );
                } catch (
                    InsufficientAvailableStockException
                ) {
                    //
                }

                $this->assertSame(
                    0,
                    InventoryReservation::query()
                        ->count(),
                );

                $this->assertNull(
                    $cart
                        ->refresh()
                        ->inventory_reserved_until
                );
            },
        );
    }

    public function test_quantity_change_during_active_window_updates_reservation(): void
    {
        $tenant =
            $this->tenant();

        $cart =
            $this->cart($tenant);

        [
            $sku,
            $location,
        ] = $this->inventory(
            $tenant,
            'SKU-A',
            10,
        );

        $this->inTenant(
            $tenant,
            function () use (
                $cart,
                $sku,
                $location,
            ): void {
                $cartService =
                    app(CartService::class);

                $item =
                    $cartService->addItem(
                        $cart,
                        $sku,
                        $location,
                        2,
                    );

                app(
                    CartCheckoutReservationService::class
                )->begin(
                    $cart
                );

                $cartService->setQuantity(
                    $cart,
                    $item,
                    6,
                );

                $reservation =
                    InventoryReservation::query()
                        ->where(
                            'reference_id',
                            $item->public_id,
                        )
                        ->where(
                            'status',
                            InventoryReservationStatus::ACTIVE,
                        )
                        ->firstOrFail();

                $this->assertSame(
                    6,
                    $reservation->quantity,
                );

                $this->assertSame(
                    4,
                    app(
                        InventoryAvailabilityService::class
                    )->availableToSell(
                        $sku,
                        $location,
                    ),
                );
            },
        );
    }

    public function test_failed_quantity_growth_rolls_back_cart_quantity(): void
    {
        $tenant =
            $this->tenant();

        $cart =
            $this->cart($tenant);

        [
            $sku,
            $location,
        ] = $this->inventory(
            $tenant,
            'SKU-A',
            5,
        );

        $this->inTenant(
            $tenant,
            function () use (
                $cart,
                $sku,
                $location,
            ): void {
                $cartService =
                    app(CartService::class);

                $item =
                    $cartService->addItem(
                        $cart,
                        $sku,
                        $location,
                        3,
                    );

                app(
                    CartCheckoutReservationService::class
                )->begin(
                    $cart
                );

                try {
                    $cartService->setQuantity(
                        $cart,
                        $item,
                        6,
                    );

                    $this->fail(
                        'Expected insufficient stock.'
                    );
                } catch (
                    InsufficientAvailableStockException
                ) {
                    //
                }

                $this->assertSame(
                    3,
                    $item
                        ->refresh()
                        ->quantity,
                );

                $this->assertSame(
                    3,
                    InventoryReservation::query()
                        ->where(
                            'reference_id',
                            $item->public_id,
                        )
                        ->firstOrFail()
                        ->quantity,
                );
            },
        );
    }

    public function test_new_item_during_active_window_is_reserved(): void
    {
        $tenant =
            $this->tenant();

        $cart =
            $this->cart($tenant);

        [
            $skuA,
            $location,
        ] = $this->inventory(
            $tenant,
            'SKU-A',
            10,
        );

        [
            $skuB,
        ] = $this->inventory(
            $tenant,
            'SKU-B',
            10,
        );

        $this->inTenant(
            $tenant,
            function () use (
                $cart,
                $skuA,
                $skuB,
                $location,
            ): void {
                $cartService =
                    app(CartService::class);

                $cartService->addItem(
                    $cart,
                    $skuA,
                    $location,
                    1,
                );

                app(
                    CartCheckoutReservationService::class
                )->begin(
                    $cart
                );

                $newItem =
                    $cartService->addItem(
                        $cart,
                        $skuB,
                        $location,
                        4,
                    );

                $this->assertSame(
                    4,
                    InventoryReservation::query()
                        ->where(
                            'reference_id',
                            $newItem->public_id,
                        )
                        ->where(
                            'status',
                            InventoryReservationStatus::ACTIVE,
                        )
                        ->firstOrFail()
                        ->quantity,
                );
            },
        );
    }

    public function test_remove_item_during_active_window_releases_reservation(): void
    {
        $tenant =
            $this->tenant();

        $cart =
            $this->cart($tenant);

        [
            $sku,
            $location,
        ] = $this->inventory(
            $tenant,
            'SKU-A',
            10,
        );

        $this->inTenant(
            $tenant,
            function () use (
                $cart,
                $sku,
                $location,
            ): void {
                $cartService =
                    app(CartService::class);

                $item =
                    $cartService->addItem(
                        $cart,
                        $sku,
                        $location,
                        5,
                    );

                app(
                    CartCheckoutReservationService::class
                )->begin(
                    $cart
                );

                $cartService->removeItem(
                    $cart,
                    $item,
                );

                $this->assertSame(
                    InventoryReservationStatus::RELEASED,
                    InventoryReservation::query()
                        ->where(
                            'reference_id',
                            $item->public_id,
                        )
                        ->firstOrFail()
                        ->status,
                );

                $this->assertSame(
                    10,
                    app(
                        InventoryAvailabilityService::class
                    )->availableToSell(
                        $sku,
                        $location,
                    ),
                );
            },
        );
    }

    public function test_release_clears_window_and_restores_ats(): void
    {
        $tenant =
            $this->tenant();

        $cart =
            $this->cart($tenant);

        [
            $sku,
            $location,
        ] = $this->inventory(
            $tenant,
            'SKU-A',
            10,
        );

        $this->inTenant(
            $tenant,
            function () use (
                $cart,
                $sku,
                $location,
            ): void {
                $cartService =
                    app(CartService::class);

                $cartService->addItem(
                    $cart,
                    $sku,
                    $location,
                    5,
                );

                $checkout =
                    app(
                        CartCheckoutReservationService::class
                    );

                $checkout->begin(
                    $cart
                );

                $releasedCart =
                    $checkout->release(
                        $cart
                    );

                $this->assertNull(
                    $releasedCart
                        ->inventory_reserved_until
                );

                $this->assertSame(
                    10,
                    app(
                        InventoryAvailabilityService::class
                    )->availableToSell(
                        $sku,
                        $location,
                    ),
                );
            },
        );
    }

    public function test_invalid_ttl_and_empty_cart_are_rejected(): void
    {
        $tenant =
            $this->tenant();

        $cart =
            $this->cart($tenant);

        $this->inTenant(
            $tenant,
            function () use ($cart): void {
                $service =
                    app(
                        CartCheckoutReservationService::class
                    );

                try {
                    $service->begin(
                        $cart,
                        31,
                    );

                    $this->fail(
                        'Expected invalid TTL.'
                    );
                } catch (
                    InvalidArgumentException
                ) {
                    //
                }

                $this->expectException(
                    LogicException::class
                );

                $service->begin(
                    $cart
                );
            },
        );
    }

    public function test_repeated_begin_does_not_extend_active_reservation_window(): void
    {
        $tenant =
            $this->tenant();

        $cart =
            $this->cart($tenant);

        [
            $sku,
            $location,
        ] = $this->inventory(
            $tenant,
            'SKU-A',
            10,
        );

        $this->inTenant(
            $tenant,
            function () use (
                $cart,
                $sku,
                $location,
            ): void {
                CarbonImmutable::setTestNow(
                    '2026-09-17 10:00:00'
                );

                try {
                    $cartService =
                        app(CartService::class);

                    $item =
                        $cartService->addItem(
                            $cart,
                            $sku,
                            $location,
                            2,
                        );

                    $checkout =
                        app(
                            CartCheckoutReservationService::class
                        );

                    $first =
                        $checkout->begin(
                            $cart
                        );

                    $firstExpiry =
                        $first
                            ->inventory_reserved_until
                            ->getTimestamp();

                    CarbonImmutable::setTestNow(
                        '2026-09-17 10:05:00'
                    );

                    $second =
                        $checkout->begin(
                            $cart
                        );

                    $reservation =
                        InventoryReservation::query()
                            ->where(
                                'reference_id',
                                $item->public_id,
                            )
                            ->where(
                                'status',
                                InventoryReservationStatus::ACTIVE,
                            )
                            ->firstOrFail();

                    $this->assertSame(
                        $firstExpiry,
                        $second
                            ->inventory_reserved_until
                            ->getTimestamp(),
                    );

                    $this->assertSame(
                        $firstExpiry,
                        $reservation
                            ->expires_at
                            ->getTimestamp(),
                    );
                } finally {
                    CarbonImmutable::setTestNow();
                }
            },
        );
    }

    public function test_release_succeeds_after_inventory_is_deactivated(): void
    {
        $tenant =
            $this->tenant();

        $cart =
            $this->cart($tenant);

        [
            $sku,
            $location,
        ] = $this->inventory(
            $tenant,
            'SKU-A',
            10,
        );

        $this->inTenant(
            $tenant,
            function () use (
                $cart,
                $sku,
                $location,
            ): void {
                $cartService =
                    app(CartService::class);

                $item =
                    $cartService->addItem(
                        $cart,
                        $sku,
                        $location,
                        5,
                    );

                $checkout =
                    app(
                        CartCheckoutReservationService::class
                    );

                $checkout->begin(
                    $cart
                );

                $sku->update([
                    'is_active' => false,
                ]);

                $location->update([
                    'is_active' => false,
                ]);

                $releasedCart =
                    $checkout->release(
                        $cart
                    );

                $this->assertNull(
                    $releasedCart
                        ->inventory_reserved_until
                );

                $this->assertSame(
                    InventoryReservationStatus::RELEASED,
                    InventoryReservation::query()
                        ->where(
                            'reference_id',
                            $item->public_id,
                        )
                        ->firstOrFail()
                        ->status,
                );
            },
        );
    }

    public function test_inventory_reservation_never_outlives_cart(): void
    {
        $tenant =
            $this->tenant();

        $instance =
            $this->appInstance(
                $tenant
            );

        $cartExpiresAt =
            now()->addMinutes(5);

        $cart =
            $this->inTenant(
                $tenant,
                fn (): Cart => app(CartService::class)
                    ->create(
                        $instance,
                        $cartExpiresAt,
                    )
                    ->cart,
            );

        [
            $sku,
            $location,
        ] = $this->inventory(
            $tenant,
            'SKU-SHORT-CART',
            10,
        );

        $this->inTenant(
            $tenant,
            function () use (
                $cart,
                $sku,
                $location,
            ): void {
                $item =
                    app(CartService::class)
                        ->addItem(
                            $cart,
                            $sku,
                            $location,
                            2,
                        );

                $reservedCart =
                    app(
                        CartCheckoutReservationService::class
                    )->begin(
                        $cart,
                        15,
                    );

                $reservation =
                    InventoryReservation::query()
                        ->where(
                            'reference_id',
                            $item->public_id,
                        )
                        ->where(
                            'status',
                            InventoryReservationStatus::ACTIVE,
                        )
                        ->firstOrFail();

                $this->assertSame(
                    $reservedCart
                        ->expires_at
                        ->getTimestamp(),
                    $reservedCart
                        ->inventory_reserved_until
                        ->getTimestamp(),
                );

                $this->assertSame(
                    $reservedCart
                        ->expires_at
                        ->getTimestamp(),
                    $reservation
                        ->expires_at
                        ->getTimestamp(),
                );
            },
        );
    }
}
