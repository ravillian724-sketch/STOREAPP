<?php

namespace Tests\Feature;

use App\Models\AppInstance;
use App\Models\Cart;
use App\Models\InventoryLocation;
use App\Models\InventoryReservation;
use App\Models\Product;
use App\Models\Sku;
use App\Models\Tenant;
use App\Services\Cart\CartCheckoutReservationService;
use App\Services\Cart\CartExpirationSweepService;
use App\Services\Cart\CartService;
use App\Services\Inventory\InventoryAvailabilityService;
use App\Services\Inventory\StockLedgerService;
use App\Support\Cart\CartStatus;
use App\Support\Inventory\InventoryMovementType;
use App\Support\Inventory\InventoryReservationStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CartExpirationSweepServiceTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(
        string $name,
    ): Tenant {
        return Tenant::query()->create([
            'name_ar' => $name,
            'name_en' => $name,
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

    private function cart(
        Tenant $tenant,
    ): Cart {
        $instance =
            AppInstance::query()->create([
                'tenant_id' => $tenant->id,

                'channel' => 'mobile',

                'is_active' => true,
            ]);

        return $this->inTenant(
            $tenant,
            fn (): Cart => app(CartService::class)
                ->create(
                    $instance,
                    now()->addDays(30),
                )
                ->cart,
        );
    }

    private function makeDue(
        Tenant $tenant,
        Cart $cart,
    ): void {
        $this->inTenant(
            $tenant,
            function () use ($cart): void {
                /*
                 * Checkout reservation synchronization may
                 * update a separately locked Cart instance.
                 *
                 * Refresh first so this fixture does not
                 * accidentally violate the real PostgreSQL
                 * invariant:
                 *
                 * inventory_reserved_until <= expires_at
                 */
                $cart->refresh();

                $past =
                    now()->subMinute();

                $cart->expires_at =
                    $past;

                if (
                    $cart->inventory_reserved_until
                    !== null
                ) {
                    $cart->inventory_reserved_until =
                        $past;
                }

                $cart->save();
            },
        );
    }

    private function inventory(
        Tenant $tenant,
        string $code,
    ): array {
        return $this->inTenant(
            $tenant,
            function () use ($code): array {
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
                        ->create([
                            'branch_id' => null,
                            'code' => 'LOC-'.$code,
                            'name_ar' => 'LOC-'.$code,
                            'name_en' => 'LOC-'.$code,
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
                    'sweep-opening-'.$code,
                );

                return [
                    $sku,
                    $location,
                ];
            },
        );
    }

    public function test_sweep_expires_due_carts_across_tenants(): void
    {
        $tenantA =
            $this->tenant(
                'Tenant A'
            );

        $tenantB =
            $this->tenant(
                'Tenant B'
            );

        $cartA =
            $this->cart(
                $tenantA
            );

        $cartB =
            $this->cart(
                $tenantB
            );

        $this->makeDue(
            $tenantA,
            $cartA,
        );

        $this->makeDue(
            $tenantB,
            $cartB,
        );

        $count =
            app(
                CartExpirationSweepService::class
            )->sweep(
                1
            );

        $this->assertSame(
            2,
            $count,
        );

        $this->inTenant(
            $tenantA,
            function () use ($cartA): void {
                $this->assertSame(
                    CartStatus::EXPIRED,
                    $cartA
                        ->refresh()
                        ->status,
                );
            },
        );

        $this->inTenant(
            $tenantB,
            function () use ($cartB): void {
                $this->assertSame(
                    CartStatus::EXPIRED,
                    $cartB
                        ->refresh()
                        ->status,
                );
            },
        );
    }

    public function test_sweep_leaves_future_and_terminal_carts_unchanged(): void
    {
        $tenant =
            $this->tenant(
                'Tenant A'
            );

        $future =
            $this->cart(
                $tenant
            );

        $abandoned =
            $this->cart(
                $tenant
            );

        $this->inTenant(
            $tenant,
            function () use (
                $abandoned,
            ): void {
                $abandoned->status =
                    CartStatus::ABANDONED;

                $abandoned->abandoned_at =
                    now();

                $abandoned->expires_at =
                    now()->subMinute();

                $abandoned->save();
            },
        );

        $count =
            app(
                CartExpirationSweepService::class
            )->sweep();

        $this->assertSame(
            0,
            $count,
        );

        $this->inTenant(
            $tenant,
            function () use (
                $future,
                $abandoned,
            ): void {
                $this->assertSame(
                    CartStatus::ACTIVE,
                    $future
                        ->refresh()
                        ->status,
                );

                $this->assertSame(
                    CartStatus::ABANDONED,
                    $abandoned
                        ->refresh()
                        ->status,
                );
            },
        );
    }

    public function test_inactive_tenant_is_still_cleaned_and_reservation_is_released(): void
    {
        $tenant =
            $this->tenant(
                'Tenant A'
            );

        $cart =
            $this->cart(
                $tenant
            );

        [
            $sku,
            $location,
        ] = $this->inventory(
            $tenant,
            'SWEEP-SKU',
        );

        $item =
            $this->inTenant(
                $tenant,
                function () use (
                    $cart,
                    $sku,
                    $location,
                ) {
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
                        $cart,
                        now()->addMinutes(15)
                    );

                    return $item;
                },
            );

        $this->makeDue(
            $tenant,
            $cart,
        );

        /*
         * Tenant deactivation must not strand inventory
         * commitments forever.
         */
        $tenant->update([
            'is_active' => false,
        ]);

        $count =
            app(
                CartExpirationSweepService::class
            )->sweep();

        $this->assertSame(
            1,
            $count,
        );

        $this->inTenant(
            $tenant,
            function () use (
                $cart,
                $item,
                $sku,
                $location,
            ): void {
                $this->assertSame(
                    CartStatus::EXPIRED,
                    $cart
                        ->refresh()
                        ->status,
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

    public function test_sweep_is_idempotent_and_clears_tenant_context(): void
    {
        $tenant =
            $this->tenant(
                'Tenant A'
            );

        $cart =
            $this->cart(
                $tenant
            );

        $this->makeDue(
            $tenant,
            $cart,
        );

        $service =
            app(
                CartExpirationSweepService::class
            );

        $this->assertSame(
            1,
            $service->sweep()
        );

        $this->assertNull(
            app(
                TenantContext::class
            )->id()
        );

        $this->assertSame(
            0,
            $service->sweep()
        );

        $this->assertNull(
            app(
                TenantContext::class
            )->id()
        );
    }

    public function test_console_command_validates_batch_option(): void
    {
        $this->assertSame(
            0,
            Artisan::call(
                'carts:expire',
                [
                    '--batch' => 50,
                ],
            ),
        );

        $this->assertSame(
            1,
            Artisan::call(
                'carts:expire',
                [
                    '--batch' => 0,
                ],
            ),
        );
    }
}
