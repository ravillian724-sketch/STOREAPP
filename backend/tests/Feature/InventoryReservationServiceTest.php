<?php

namespace Tests\Feature;

use App\Exceptions\Inventory\InsufficientAvailableStockException;
use App\Models\InventoryLocation;
use App\Models\InventoryPosition;
use App\Models\InventoryReservation;
use App\Models\Product;
use App\Models\Sku;
use App\Models\Tenant;
use App\Services\Inventory\InventoryAvailabilityService;
use App\Services\Inventory\InventoryReservationService;
use App\Services\Inventory\StockLedgerService;
use App\Support\Inventory\InventoryMovementType;
use App\Support\Inventory\InventoryReservationStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class InventoryReservationServiceTest extends TestCase
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
        $context = app(
            TenantContext::class
        );

        $previous = $context->id();

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

    private function sku(
        Tenant $tenant,
        string $code,
    ): Sku {
        return $this->inTenant(
            $tenant,
            function () use ($code): Sku {
                $product =
                    Product::query()->create([
                        'name_ar' => $code,
                        'name_en' => $code,
                        'is_active' => true,
                    ]);

                return Sku::query()->create([
                    'product_id' => $product->id,

                    'code' => $code,

                    'track_inventory' => true,

                    'is_active' => true,
                ]);
            },
        );
    }

    private function location(
        Tenant $tenant,
        string $code = 'MAIN',
    ): InventoryLocation {
        return $this->inTenant(
            $tenant,
            fn (): InventoryLocation => InventoryLocation::query()
                ->create([
                    'branch_id' => null,
                    'code' => $code,
                    'name_ar' => $code,
                    'name_en' => $code,
                    'type' => 'stock',
                    'is_active' => true,
                ]),
        );
    }

    private function opening(
        Sku $sku,
        InventoryLocation $location,
        int $quantity = 10,
    ): void {
        app(
            StockLedgerService::class
        )->post(
            $sku,
            $location,
            $quantity,
            InventoryMovementType::OPENING,
            'opening-'.$sku->id.'-'.$location->id,
        );
    }

    public function test_reservation_reduces_ats_and_replay_is_idempotent(): void
    {
        $tenant = $this->tenant(
            'Tenant A'
        );

        $sku = $this->sku(
            $tenant,
            'SKU-A',
        );

        $location =
            $this->location(
                $tenant
            );

        $this->inTenant(
            $tenant,
            function () use (
                $sku,
                $location,
            ): void {
                $this->opening(
                    $sku,
                    $location,
                    10,
                );

                $service = app(
                    InventoryReservationService::class
                );

                $availability = app(
                    InventoryAvailabilityService::class
                );

                $expiresAt =
                    now()->addMinutes(15);

                $first =
                    $service->reserve(
                        $sku,
                        $location,
                        4,
                        'reserve-001',
                        'cart',
                        'cart-1',
                        $expiresAt,
                    );

                $replay =
                    $service->reserve(
                        $sku,
                        $location,
                        4,
                        'reserve-001',
                        'cart',
                        'cart-1',
                        $expiresAt,
                    );

                $this->assertSame(
                    $first->id,
                    $replay->id,
                );

                $this->assertSame(
                    4,
                    $availability->reserved(
                        $sku,
                        $location,
                    ),
                );

                $this->assertSame(
                    6,
                    $availability
                        ->availableToSell(
                            $sku,
                            $location,
                        ),
                );

                $this->assertSame(
                    1,
                    InventoryReservation::query()
                        ->count(),
                );

                $this->assertSame(
                    1,
                    InventoryPosition::query()
                        ->count(),
                );
            },
        );
    }

    public function test_over_reservation_is_rejected_atomically(): void
    {
        $tenant = $this->tenant(
            'Tenant A'
        );

        $sku = $this->sku(
            $tenant,
            'SKU-A',
        );

        $location =
            $this->location(
                $tenant
            );

        $this->inTenant(
            $tenant,
            function () use (
                $sku,
                $location,
            ): void {
                $this->opening(
                    $sku,
                    $location,
                    10,
                );

                $service = app(
                    InventoryReservationService::class
                );

                $service->reserve(
                    $sku,
                    $location,
                    8,
                    'reserve-001',
                );

                $blocked = false;

                try {
                    $service->reserve(
                        $sku,
                        $location,
                        3,
                        'reserve-002',
                    );
                } catch (
                    InsufficientAvailableStockException $exception
                ) {
                    $blocked = true;

                    $this->assertSame(
                        3,
                        $exception->requestedQuantity,
                    );

                    $this->assertSame(
                        2,
                        $exception->availableQuantity,
                    );
                }

                $this->assertTrue(
                    $blocked
                );

                $this->assertSame(
                    1,
                    InventoryReservation::query()
                        ->count(),
                );
            },
        );
    }

    public function test_expired_active_reservation_does_not_reduce_ats(): void
    {
        $tenant = $this->tenant(
            'Tenant A'
        );

        $sku = $this->sku(
            $tenant,
            'SKU-A',
        );

        $location =
            $this->location(
                $tenant
            );

        $this->inTenant(
            $tenant,
            function () use (
                $sku,
                $location,
            ): void {
                $this->opening(
                    $sku,
                    $location,
                    10,
                );

                InventoryReservation::query()
                    ->create([
                        'sku_id' => $sku->id,

                        'location_id' => $location->id,

                        'quantity' => 7,

                        'status' => InventoryReservationStatus::ACTIVE,

                        'idempotency_key' => 'expired-001',

                        'expires_at' => now()->subMinute(),
                    ]);

                $availability = app(
                    InventoryAvailabilityService::class
                );

                $this->assertSame(
                    0,
                    $availability->reserved(
                        $sku,
                        $location,
                    ),
                );

                $this->assertSame(
                    10,
                    $availability
                        ->availableToSell(
                            $sku,
                            $location,
                        ),
                );
            },
        );
    }

    public function test_stock_cannot_be_reduced_below_active_reservations(): void
    {
        $tenant = $this->tenant(
            'Tenant A'
        );

        $sku = $this->sku(
            $tenant,
            'SKU-A',
        );

        $location =
            $this->location(
                $tenant
            );

        $this->inTenant(
            $tenant,
            function () use (
                $sku,
                $location,
            ): void {
                $ledger = app(
                    StockLedgerService::class
                );

                $this->opening(
                    $sku,
                    $location,
                    10,
                );

                app(
                    InventoryReservationService::class
                )->reserve(
                    $sku,
                    $location,
                    8,
                    'reserve-001',
                );

                $blocked = false;

                try {
                    $ledger->post(
                        $sku,
                        $location,
                        -3,
                        InventoryMovementType::SHIPMENT,
                        'shipment-blocked',
                    );
                } catch (
                    InsufficientAvailableStockException
                ) {
                    $blocked = true;
                }

                $this->assertTrue(
                    $blocked
                );

                $this->assertSame(
                    10,
                    $ledger->onHand(
                        $sku,
                        $location,
                    ),
                );

                $ledger->post(
                    $sku,
                    $location,
                    -2,
                    InventoryMovementType::SHIPMENT,
                    'shipment-allowed',
                );

                $this->assertSame(
                    8,
                    $ledger->onHand(
                        $sku,
                        $location,
                    ),
                );

                $this->assertSame(
                    0,
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

    public function test_stock_cannot_become_negative_without_reservations(): void
    {
        $tenant = $this->tenant(
            'Tenant A'
        );

        $sku = $this->sku(
            $tenant,
            'SKU-A',
        );

        $location =
            $this->location(
                $tenant
            );

        $this->inTenant(
            $tenant,
            function () use (
                $sku,
                $location,
            ): void {
                $this->opening(
                    $sku,
                    $location,
                    2,
                );

                $this->expectException(
                    InsufficientAvailableStockException::class
                );

                app(
                    StockLedgerService::class
                )->post(
                    $sku,
                    $location,
                    -3,
                    InventoryMovementType::SHIPMENT,
                    'negative-stock-blocked',
                );
            },
        );
    }

    public function test_idempotency_key_reuse_with_different_reservation_data_is_rejected(): void
    {
        $tenant = $this->tenant(
            'Tenant A'
        );

        $sku = $this->sku(
            $tenant,
            'SKU-A',
        );

        $location =
            $this->location(
                $tenant
            );

        $this->inTenant(
            $tenant,
            function () use (
                $sku,
                $location,
            ): void {
                $this->opening(
                    $sku,
                    $location,
                    10,
                );

                $service = app(
                    InventoryReservationService::class
                );

                $service->reserve(
                    $sku,
                    $location,
                    2,
                    'reserve-001',
                );

                $this->expectException(
                    LogicException::class
                );

                $service->reserve(
                    $sku,
                    $location,
                    3,
                    'reserve-001',
                );
            },
        );
    }
}
