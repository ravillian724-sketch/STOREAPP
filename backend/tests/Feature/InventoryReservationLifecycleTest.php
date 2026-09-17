<?php

namespace Tests\Feature;

use App\Exceptions\Inventory\InsufficientAvailableStockException;
use App\Models\InventoryLocation;
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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryReservationLifecycleTest extends TestCase
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

        $context->set(
            $tenant->id
        );

        try {
            return $callback();
        } finally {
            $context->clear();
        }
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

                        'code' => 'SKU-A',

                        'track_inventory' => true,

                        'is_active' => true,
                    ]);

                $location =
                    InventoryLocation::query()
                        ->create([
                            'branch_id' => null,
                            'code' => 'MAIN',
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

    public function test_reference_sync_creates_reservation_and_reduces_ats(): void
    {
        $tenant =
            $this->tenant();

        [
            $sku,
            $location,
        ] = $this->inventory(
            $tenant
        );

        $this->inTenant(
            $tenant,
            function () use (
                $sku,
                $location,
            ): void {
                $reservation =
                    app(
                        InventoryReservationService::class
                    )->synchronizeReference(
                        $sku,
                        $location,
                        4,
                        'cart_item',
                        'item-1',
                        now()->addMinutes(15),
                    );

                $this->assertSame(
                    4,
                    $reservation->quantity,
                );

                $this->assertSame(
                    InventoryReservationStatus::ACTIVE,
                    $reservation->status,
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
            },
        );
    }

    public function test_reference_sync_updates_same_active_reservation(): void
    {
        $tenant =
            $this->tenant();

        [
            $sku,
            $location,
        ] = $this->inventory(
            $tenant
        );

        $this->inTenant(
            $tenant,
            function () use (
                $sku,
                $location,
            ): void {
                $service =
                    app(
                        InventoryReservationService::class
                    );

                $first =
                    $service
                        ->synchronizeReference(
                            $sku,
                            $location,
                            2,
                            'cart_item',
                            'item-1',
                            now()->addMinutes(15),
                        );

                $second =
                    $service
                        ->synchronizeReference(
                            $sku,
                            $location,
                            7,
                            'cart_item',
                            'item-1',
                            now()->addMinutes(20),
                        );

                $this->assertSame(
                    $first->id,
                    $second->id,
                );

                $this->assertSame(
                    7,
                    $second->quantity,
                );

                $this->assertSame(
                    1,
                    InventoryReservation::query()
                        ->count(),
                );

                $this->assertSame(
                    3,
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

    public function test_reference_sync_can_reduce_quantity_and_restore_ats(): void
    {
        $tenant =
            $this->tenant();

        [
            $sku,
            $location,
        ] = $this->inventory(
            $tenant
        );

        $this->inTenant(
            $tenant,
            function () use (
                $sku,
                $location,
            ): void {
                $service =
                    app(
                        InventoryReservationService::class
                    );

                $service
                    ->synchronizeReference(
                        $sku,
                        $location,
                        8,
                        'cart_item',
                        'item-1',
                        now()->addMinutes(15),
                    );

                $service
                    ->synchronizeReference(
                        $sku,
                        $location,
                        3,
                        'cart_item',
                        'item-1',
                        now()->addMinutes(15),
                    );

                $this->assertSame(
                    7,
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

    public function test_reference_sync_rejects_growth_beyond_capacity_without_mutation(): void
    {
        $tenant =
            $this->tenant();

        [
            $sku,
            $location,
        ] = $this->inventory(
            $tenant
        );

        $this->inTenant(
            $tenant,
            function () use (
                $sku,
                $location,
            ): void {
                $service =
                    app(
                        InventoryReservationService::class
                    );

                $reservation =
                    $service
                        ->synchronizeReference(
                            $sku,
                            $location,
                            8,
                            'cart_item',
                            'item-1',
                            now()->addMinutes(15),
                        );

                try {
                    $service
                        ->synchronizeReference(
                            $sku,
                            $location,
                            11,
                            'cart_item',
                            'item-1',
                            now()->addMinutes(15),
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
                    8,
                    $reservation
                        ->refresh()
                        ->quantity,
                );
            },
        );
    }

    public function test_release_is_idempotent_and_restores_ats(): void
    {
        $tenant =
            $this->tenant();

        [
            $sku,
            $location,
        ] = $this->inventory(
            $tenant
        );

        $this->inTenant(
            $tenant,
            function () use (
                $sku,
                $location,
            ): void {
                $service =
                    app(
                        InventoryReservationService::class
                    );

                $reservation =
                    $service
                        ->synchronizeReference(
                            $sku,
                            $location,
                            6,
                            'cart_item',
                            'item-1',
                            now()->addMinutes(15),
                        );

                $released =
                    $service
                        ->releaseReference(
                            $sku,
                            $location,
                            'cart_item',
                            'item-1',
                        );

                $this->assertSame(
                    $reservation->id,
                    $released?->id,
                );

                $this->assertSame(
                    InventoryReservationStatus::RELEASED,
                    $released?->status,
                );

                $this->assertNotNull(
                    $released?->released_at
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

                $this->assertNull(
                    $service
                        ->releaseReference(
                            $sku,
                            $location,
                            'cart_item',
                            'item-1',
                        )
                );
            },
        );
    }

    public function test_database_blocks_two_active_reservations_for_same_reference(): void
    {
        $tenant =
            $this->tenant();

        [
            $sku,
            $location,
        ] = $this->inventory(
            $tenant
        );

        $this->inTenant(
            $tenant,
            function () use (
                $sku,
                $location,
            ): void {
                InventoryReservation::query()
                    ->create([
                        'sku_id' => $sku->id,

                        'location_id' => $location->id,

                        'quantity' => 1,

                        'status' => InventoryReservationStatus::ACTIVE,

                        'idempotency_key' => 'duplicate-ref-1',

                        'reference_type' => 'cart_item',

                        'reference_id' => 'same-item',

                        'expires_at' => now()->addMinutes(15),
                    ]);

                $this->expectException(
                    QueryException::class
                );

                InventoryReservation::query()
                    ->create([
                        'sku_id' => $sku->id,

                        'location_id' => $location->id,

                        'quantity' => 1,

                        'status' => InventoryReservationStatus::ACTIVE,

                        'idempotency_key' => 'duplicate-ref-2',

                        'reference_type' => 'cart_item',

                        'reference_id' => 'same-item',

                        'expires_at' => now()->addMinutes(15),
                    ]);
            },
        );
    }

    public function test_released_reference_can_be_reserved_again(): void
    {
        $tenant =
            $this->tenant();

        [
            $sku,
            $location,
        ] = $this->inventory(
            $tenant
        );

        $this->inTenant(
            $tenant,
            function () use (
                $sku,
                $location,
            ): void {
                $service =
                    app(
                        InventoryReservationService::class
                    );

                $first =
                    $service->synchronizeReference(
                        $sku,
                        $location,
                        2,
                        'cart_item',
                        'item-reusable',
                        now()->addMinutes(15),
                    );

                $service->releaseReference(
                    $sku,
                    $location,
                    'cart_item',
                    'item-reusable',
                );

                $second =
                    $service->synchronizeReference(
                        $sku,
                        $location,
                        3,
                        'cart_item',
                        'item-reusable',
                        now()->addMinutes(15),
                    );

                $this->assertNotSame(
                    $first->id,
                    $second->id,
                );

                $this->assertSame(
                    InventoryReservationStatus::RELEASED,
                    $first->refresh()->status,
                );

                $this->assertSame(
                    InventoryReservationStatus::ACTIVE,
                    $second->status,
                );

                $this->assertSame(
                    3,
                    $second->quantity,
                );
            },
        );
    }
}
