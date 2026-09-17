<?php

namespace Tests\Feature;

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
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class InventoryReservationOwnershipTransferTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(
        string $name = 'Tenant A',
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

    private function sku(
        Tenant $tenant,
        string $code = 'SKU-A',
    ): Sku {
        return $this->inTenant(
            $tenant,
            function () use (
                $code
            ): Sku {
                $product =
                    Product::query()
                        ->create([
                            'name_ar' => $code,
                            'name_en' => $code,
                            'is_active' => true,
                        ]);

                return Sku::query()
                    ->create([
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

    public function test_transfer_moves_active_ownership_without_changing_ats(): void
    {
        $tenant =
            $this->tenant();

        $sku =
            $this->sku(
                $tenant
            );

        $location =
            $this->location(
                $tenant
            );

        $at =
            CarbonImmutable::now();

        $expiresAt =
            $at->addMinutes(15);

        $this->inTenant(
            $tenant,
            function () use (
                $sku,
                $location,
                $at,
                $expiresAt,
            ): void {
                $this->opening(
                    $sku,
                    $location,
                    10,
                );

                $reservations =
                    app(
                        InventoryReservationService::class
                    );

                $availability =
                    app(
                        InventoryAvailabilityService::class
                    );

                $source =
                    $reservations
                        ->reserve(
                            $sku,
                            $location,
                            4,
                            'checkout-line-a',
                            'cart_item',
                            'cart-item-a',
                            $expiresAt,
                        );

                $beforeAts =
                    $availability
                        ->availableToSell(
                            $sku,
                            $location,
                        );

                $transferred =
                    $reservations
                        ->transferReferenceOwnership(
                            $sku,
                            $location,
                            4,
                            'cart_item',
                            'cart-item-a',
                            'order_item',
                            'order-item-a',
                            $at,
                        );

                $afterAts =
                    $availability
                        ->availableToSell(
                            $sku,
                            $location,
                        );

                $this->assertSame(
                    $source->id,
                    $transferred->id,
                );

                $this->assertSame(
                    InventoryReservationStatus::ACTIVE,
                    $transferred->status,
                );

                $this->assertSame(
                    4,
                    $transferred->quantity,
                );

                $this->assertSame(
                    'order_item',
                    $transferred->reference_type,
                );

                $this->assertSame(
                    'order-item-a',
                    $transferred->reference_id,
                );

                $this->assertSame(
                    $expiresAt->getTimestamp(),
                    $transferred
                        ->expires_at
                        ->getTimestamp(),
                );

                $this->assertNull(
                    $transferred->released_at
                );

                $this->assertNull(
                    $transferred->consumed_at
                );

                $this->assertSame(
                    6,
                    $beforeAts,
                );

                $this->assertSame(
                    $beforeAts,
                    $afterAts,
                );

                $this->assertNull(
                    InventoryReservation::query()
                        ->where(
                            'reference_type',
                            'cart_item',
                        )
                        ->where(
                            'reference_id',
                            'cart-item-a',
                        )
                        ->first()
                );

                $this->assertSame(
                    1,
                    InventoryReservation::query()
                        ->count(),
                );
            },
        );
    }

    public function test_transfer_replay_returns_same_target_reservation(): void
    {
        $tenant =
            $this->tenant();

        $sku =
            $this->sku(
                $tenant
            );

        $location =
            $this->location(
                $tenant
            );

        $at =
            CarbonImmutable::now();

        $this->inTenant(
            $tenant,
            function () use (
                $sku,
                $location,
                $at,
            ): void {
                $this->opening(
                    $sku,
                    $location
                );

                $service =
                    app(
                        InventoryReservationService::class
                    );

                $service->reserve(
                    $sku,
                    $location,
                    3,
                    'checkout-line-a',
                    'cart_item',
                    'cart-item-a',
                    $at->addMinutes(15),
                );

                $first =
                    $service
                        ->transferReferenceOwnership(
                            $sku,
                            $location,
                            3,
                            'cart_item',
                            'cart-item-a',
                            'order_item',
                            'order-item-a',
                            $at,
                        );

                $replay =
                    $service
                        ->transferReferenceOwnership(
                            $sku,
                            $location,
                            3,
                            'cart_item',
                            'cart-item-a',
                            'order_item',
                            'order-item-a',
                            $at,
                        );

                $this->assertSame(
                    $first->id,
                    $replay->id,
                );

                $this->assertSame(
                    1,
                    InventoryReservation::query()
                        ->count(),
                );
            },
        );
    }

    public function test_transfer_rejects_expired_source_without_mutating_reference(): void
    {
        $tenant =
            $this->tenant();

        $sku =
            $this->sku(
                $tenant
            );

        $location =
            $this->location(
                $tenant
            );

        $at =
            CarbonImmutable::now();

        $this->inTenant(
            $tenant,
            function () use (
                $sku,
                $location,
                $at,
            ): void {
                InventoryReservation::query()
                    ->create([
                        'sku_id' => $sku->id,

                        'location_id' => $location->id,

                        'quantity' => 2,

                        'status' => InventoryReservationStatus::ACTIVE,

                        'idempotency_key' => 'expired-source',

                        'reference_type' => 'cart_item',

                        'reference_id' => 'cart-item-a',

                        'expires_at' => $at->subSecond(),
                    ]);

                try {
                    app(
                        InventoryReservationService::class
                    )->transferReferenceOwnership(
                        $sku,
                        $location,
                        2,
                        'cart_item',
                        'cart-item-a',
                        'order_item',
                        'order-item-a',
                        $at,
                    );

                    $this->fail(
                        'Expected expired source transfer to fail.'
                    );
                } catch (
                    LogicException
                ) {
                    $this->addToAssertionCount(1);
                }

                $reservation =
                    InventoryReservation::query()
                        ->firstOrFail();

                $this->assertSame(
                    'cart_item',
                    $reservation
                        ->reference_type,
                );

                $this->assertSame(
                    'cart-item-a',
                    $reservation
                        ->reference_id,
                );
            },
        );
    }

    public function test_transfer_rejects_quantity_mismatch_without_mutation(): void
    {
        $tenant =
            $this->tenant();

        $sku =
            $this->sku(
                $tenant
            );

        $location =
            $this->location(
                $tenant
            );

        $at =
            CarbonImmutable::now();

        $this->inTenant(
            $tenant,
            function () use (
                $sku,
                $location,
                $at,
            ): void {
                $this->opening(
                    $sku,
                    $location,
                    10,
                );

                $service =
                    app(
                        InventoryReservationService::class
                    );

                $service->reserve(
                    $sku,
                    $location,
                    4,
                    'checkout-line-a',
                    'cart_item',
                    'cart-item-a',
                    $at->addMinutes(15),
                );

                try {
                    $service
                        ->transferReferenceOwnership(
                            $sku,
                            $location,
                            3,
                            'cart_item',
                            'cart-item-a',
                            'order_item',
                            'order-item-a',
                            $at,
                        );

                    $this->fail(
                        'Expected quantity mismatch to fail.'
                    );
                } catch (
                    LogicException
                ) {
                    $this->addToAssertionCount(1);
                }

                $reservation =
                    InventoryReservation::query()
                        ->firstOrFail();

                $this->assertSame(
                    'cart_item',
                    $reservation
                        ->reference_type,
                );

                $this->assertSame(
                    4,
                    $reservation->quantity,
                );
            },
        );
    }

    public function test_transfer_rejects_existing_active_target_and_preserves_source(): void
    {
        $tenant =
            $this->tenant();

        $sku =
            $this->sku(
                $tenant
            );

        $location =
            $this->location(
                $tenant
            );

        $at =
            CarbonImmutable::now();

        $this->inTenant(
            $tenant,
            function () use (
                $sku,
                $location,
                $at,
            ): void {
                $this->opening(
                    $sku,
                    $location,
                    10,
                );

                $service =
                    app(
                        InventoryReservationService::class
                    );

                $service->reserve(
                    $sku,
                    $location,
                    3,
                    'source-reservation',
                    'cart_item',
                    'cart-item-a',
                    $at->addMinutes(15),
                );

                $service->reserve(
                    $sku,
                    $location,
                    2,
                    'target-reservation',
                    'order_item',
                    'order-item-a',
                    $at->addMinutes(15),
                );

                try {
                    $service
                        ->transferReferenceOwnership(
                            $sku,
                            $location,
                            3,
                            'cart_item',
                            'cart-item-a',
                            'order_item',
                            'order-item-a',
                            $at,
                        );

                    $this->fail(
                        'Expected active target collision to fail.'
                    );
                } catch (
                    LogicException
                ) {
                    $this->addToAssertionCount(1);
                }

                $source =
                    InventoryReservation::query()
                        ->where(
                            'reference_type',
                            'cart_item',
                        )
                        ->where(
                            'reference_id',
                            'cart-item-a',
                        )
                        ->first();

                $this->assertNotNull(
                    $source
                );

                $this->assertSame(
                    3,
                    $source->quantity,
                );

                $this->assertSame(
                    2,
                    InventoryReservation::query()
                        ->count(),
                );
            },
        );
    }

    public function test_transfer_rejects_foreign_tenant_inventory(): void
    {
        $tenantA =
            $this->tenant(
                'Tenant A'
            );

        $tenantB =
            $this->tenant(
                'Tenant B'
            );

        $foreignSku =
            $this->sku(
                $tenantB,
                'SKU-B',
            );

        $foreignLocation =
            $this->location(
                $tenantB,
                'OTHER',
            );

        $this->expectException(
            LogicException::class
        );

        $this->inTenant(
            $tenantA,
            fn () => app(
                InventoryReservationService::class
            )->transferReferenceOwnership(
                $foreignSku,
                $foreignLocation,
                1,
                'cart_item',
                'cart-item-b',
                'order_item',
                'order-item-b',
                CarbonImmutable::now(),
            ),
        );
    }
}
