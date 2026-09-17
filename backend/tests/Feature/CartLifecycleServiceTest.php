<?php

namespace Tests\Feature;

use App\Exceptions\Cart\CartNotMutableException;
use App\Models\AppInstance;
use App\Models\InventoryLocation;
use App\Models\InventoryReservation;
use App\Models\Product;
use App\Models\Sku;
use App\Models\Tenant;
use App\Services\Cart\CartCheckoutReservationService;
use App\Services\Cart\CartLifecycleService;
use App\Services\Cart\CartService;
use App\Services\Inventory\InventoryAvailabilityService;
use App\Services\Inventory\StockLedgerService;
use App\Support\Cart\CartStatus;
use App\Support\Inventory\InventoryMovementType;
use App\Support\Inventory\InventoryReservationStatus;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartLifecycleServiceTest extends TestCase
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

    private function cart(
        Tenant $tenant,
    ): array {
        $instance =
            $this->appInstance(
                $tenant
            );

        $created =
            $this->inTenant(
                $tenant,
                fn () => app(CartService::class)
                    ->create(
                        $instance
                    ),
            );

        return [
            $created->cart,
            $created->token,
        ];
    }

    private function inventory(
        Tenant $tenant,
    ): array {
        return $this->inTenant(
            $tenant,
            function (): array {
                $product =
                    Product::query()->create([
                        'name_ar' => 'Product',
                        'name_en' => 'Product',
                        'is_active' => true,
                    ]);

                $sku =
                    Sku::query()->create([
                        'product_id' => $product->id,

                        'code' => 'LIFECYCLE-SKU',

                        'track_inventory' => true,

                        'is_active' => true,
                    ]);

                $location =
                    InventoryLocation::query()
                        ->create([
                            'branch_id' => null,
                            'code' => 'LIFECYCLE-MAIN',
                            'name_ar' => 'MAIN',
                            'name_en' => 'MAIN',
                            'type' => 'stock',
                            'is_active' => true,
                        ]);

                app(
                    StockLedgerService::class
                )->post(
                    $sku,
                    $location,
                    10,
                    InventoryMovementType::OPENING,
                    'lifecycle-opening',
                );

                return [
                    $sku,
                    $location,
                ];
            },
        );
    }

    public function test_active_cart_can_be_abandoned(): void
    {
        $tenant =
            $this->tenant();

        [$cart] =
            $this->cart($tenant);

        $result =
            $this->inTenant(
                $tenant,
                fn () => app(CartLifecycleService::class)
                    ->abandon(
                        $cart
                    ),
            );

        $this->assertSame(
            CartStatus::ABANDONED,
            $result->status,
        );

        $this->assertNotNull(
            $result->abandoned_at
        );

        $this->assertNull(
            $result->expired_at
        );

        $this->assertNull(
            $result->converted_at
        );
    }

    public function test_abandon_releases_active_reservations_and_restores_ats(): void
    {
        $tenant =
            $this->tenant();

        [$cart] =
            $this->cart($tenant);

        [
            $sku,
            $location,
        ] = $this->inventory(
            $tenant
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
                            4,
                        );

                app(
                    CartCheckoutReservationService::class
                )->begin(
                    $cart
                );

                $this->assertSame(
                    6,
                    app(
                        InventoryAvailabilityService::class
                    )->availableToSell(
                        $sku,
                        $location,
                    ),
                );

                $result =
                    app(
                        CartLifecycleService::class
                    )->abandon(
                        $cart
                    );

                $this->assertNull(
                    $result
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

    public function test_abandon_is_idempotent(): void
    {
        $tenant =
            $this->tenant();

        [$cart] =
            $this->cart($tenant);

        CarbonImmutable::setTestNow(
            '2026-09-17 10:00:00'
        );

        try {
            $first =
                $this->inTenant(
                    $tenant,
                    fn () => app(
                        CartLifecycleService::class
                    )->abandon(
                        $cart
                    ),
                );

            $firstTimestamp =
                $first
                    ->abandoned_at
                    ->getTimestamp();

            CarbonImmutable::setTestNow(
                '2026-09-17 11:00:00'
            );

            $second =
                $this->inTenant(
                    $tenant,
                    fn () => app(
                        CartLifecycleService::class
                    )->abandon(
                        $cart
                    ),
                );

            $this->assertSame(
                $firstTimestamp,
                $second
                    ->abandoned_at
                    ->getTimestamp(),
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_due_cart_expires_instead_of_becoming_abandoned(): void
    {
        $tenant =
            $this->tenant();

        [$cart] =
            $this->cart($tenant);

        $this->inTenant(
            $tenant,
            function () use ($cart): void {
                $cart->expires_at =
                    now()->subMinute();

                $cart->save();

                $result =
                    app(
                        CartLifecycleService::class
                    )->abandon(
                        $cart
                    );

                $this->assertSame(
                    CartStatus::EXPIRED,
                    $result->status,
                );

                $this->assertNotNull(
                    $result->expired_at
                );

                $this->assertNull(
                    $result->abandoned_at
                );
            },
        );
    }

    public function test_future_cart_is_not_expired(): void
    {
        $tenant =
            $this->tenant();

        [$cart] =
            $this->cart($tenant);

        $result =
            $this->inTenant(
                $tenant,
                fn () => app(CartLifecycleService::class)
                    ->expireIfDue(
                        $cart
                    ),
            );

        $this->assertSame(
            CartStatus::ACTIVE,
            $result->status,
        );

        $this->assertNull(
            $result->expired_at
        );
    }

    public function test_expiration_releases_existing_reservation(): void
    {
        $tenant =
            $this->tenant();

        [$cart] =
            $this->cart($tenant);

        [
            $sku,
            $location,
        ] = $this->inventory(
            $tenant
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
                            5,
                        );

                app(
                    CartCheckoutReservationService::class
                )->begin(
                    $cart
                );

                $past =
                    now()->subMinute();

                $cart->refresh();

                $cart->expires_at =
                    $past;

                $cart->inventory_reserved_until =
                    $past;

                $cart->save();

                $expired =
                    app(
                        CartLifecycleService::class
                    )->expireIfDue(
                        $cart
                    );

                $this->assertSame(
                    CartStatus::EXPIRED,
                    $expired->status,
                );

                $this->assertNull(
                    $expired
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

    public function test_terminal_cart_is_not_rewritten_by_expiration(): void
    {
        $tenant =
            $this->tenant();

        [$cart] =
            $this->cart($tenant);

        $this->inTenant(
            $tenant,
            function () use ($cart): void {
                $lifecycle =
                    app(
                        CartLifecycleService::class
                    );

                $abandoned =
                    $lifecycle->abandon(
                        $cart
                    );

                $abandoned->expires_at =
                    now()->subMinute();

                $abandoned->save();

                $result =
                    $lifecycle->expireIfDue(
                        $abandoned
                    );

                $this->assertSame(
                    CartStatus::ABANDONED,
                    $result->status,
                );

                $this->assertNotNull(
                    $result->abandoned_at
                );

                $this->assertNull(
                    $result->expired_at
                );
            },
        );
    }

    public function test_resolve_normalizes_due_cart_to_expired(): void
    {
        $tenant =
            $this->tenant();

        [
            $cart,
            $token,
        ] = $this->cart(
            $tenant
        );

        $this->inTenant(
            $tenant,
            function () use (
                $cart,
                $token,
            ): void {
                $cart->expires_at =
                    now()->subMinute();

                $cart->save();

                $resolved =
                    app(CartService::class)
                        ->resolve(
                            $cart->public_id,
                            $token,
                        );

                $this->assertSame(
                    CartStatus::EXPIRED,
                    $resolved->status,
                );

                $this->assertNotNull(
                    $resolved->expired_at
                );
            },
        );
    }

    public function test_converted_cart_cannot_be_abandoned(): void
    {
        $tenant =
            $this->tenant();

        [$cart] =
            $this->cart($tenant);

        $this->inTenant(
            $tenant,
            function () use ($cart): void {
                $cart->status =
                    CartStatus::CONVERTED;

                $cart->converted_at =
                    now();

                $cart->save();

                $this->expectException(
                    CartNotMutableException::class
                );

                app(
                    CartLifecycleService::class
                )->abandon(
                    $cart
                );
            },
        );
    }
}
