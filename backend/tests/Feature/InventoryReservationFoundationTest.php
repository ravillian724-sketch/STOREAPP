<?php

namespace Tests\Feature;

use App\Models\InventoryLocation;
use App\Models\InventoryReservation;
use App\Models\Product;
use App\Models\Sku;
use App\Models\Tenant;
use App\Support\Inventory\InventoryReservationStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryReservationFoundationTest extends TestCase
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
        string $code,
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

    private function reservation(
        Tenant $tenant,
        Sku $sku,
        InventoryLocation $location,
        string $key,
    ): InventoryReservation {
        return $this->inTenant(
            $tenant,
            fn (): InventoryReservation => InventoryReservation::query()
                ->create([
                    'sku_id' => $sku->id,

                    'location_id' => $location->id,

                    'quantity' => 2,

                    'status' => InventoryReservationStatus::ACTIVE,

                    'idempotency_key' => $key,

                    'reference_type' => 'test',

                    'reference_id' => '1',

                    'expires_at' => now()->addMinutes(15),
                ]),
        );
    }

    public function test_reservations_fail_closed_without_tenant_context(): void
    {
        $tenant = $this->tenant(
            'Tenant A'
        );

        $sku = $this->sku(
            $tenant,
            'SKU-A',
        );

        $location = $this->location(
            $tenant,
            'MAIN',
        );

        $this->reservation(
            $tenant,
            $sku,
            $location,
            'reservation-001',
        );

        $this->assertSame(
            0,
            InventoryReservation::query()
                ->count(),
        );
    }

    public function test_reservation_is_automatically_owned_by_active_tenant(): void
    {
        $tenant = $this->tenant(
            'Tenant A'
        );

        $sku = $this->sku(
            $tenant,
            'SKU-A',
        );

        $location = $this->location(
            $tenant,
            'MAIN',
        );

        $reservation =
            $this->reservation(
                $tenant,
                $sku,
                $location,
                'reservation-002',
            );

        $this->assertSame(
            $tenant->id,
            (int) $reservation->tenant_id,
        );

        $this->assertSame(
            InventoryReservationStatus::ACTIVE,
            $reservation->status,
        );

        $this->assertSame(
            2,
            $reservation->quantity,
        );
    }

    public function test_database_rejects_cross_tenant_reservation_references(): void
    {
        $tenantA = $this->tenant(
            'Tenant A'
        );

        $tenantB = $this->tenant(
            'Tenant B'
        );

        $skuA = $this->sku(
            $tenantA,
            'SKU-A',
        );

        $locationB = $this->location(
            $tenantB,
            'B-MAIN',
        );

        $blocked = false;

        try {
            $this->inTenant(
                $tenantA,
                function () use (
                    $skuA,
                    $locationB,
                ): void {
                    InventoryReservation::query()
                        ->create([
                            'sku_id' => $skuA->id,

                            'location_id' => $locationB->id,

                            'quantity' => 1,

                            'status' => InventoryReservationStatus::ACTIVE,

                            'idempotency_key' => 'cross-tenant-001',
                        ]);
                },
            );
        } catch (QueryException) {
            $blocked = true;
        }

        $this->assertTrue(
            $blocked,
            'Database allowed a cross-tenant inventory reservation.',
        );
    }

    public function test_idempotency_key_is_unique_inside_tenant_but_reusable_across_tenants(): void
    {
        $tenantA = $this->tenant(
            'Tenant A'
        );

        $tenantB = $this->tenant(
            'Tenant B'
        );

        $skuA = $this->sku(
            $tenantA,
            'SKU-A',
        );

        $skuB = $this->sku(
            $tenantB,
            'SKU-B',
        );

        $locationA = $this->location(
            $tenantA,
            'A-MAIN',
        );

        $locationB = $this->location(
            $tenantB,
            'B-MAIN',
        );

        $this->reservation(
            $tenantA,
            $skuA,
            $locationA,
            'shared-key',
        );

        $this->reservation(
            $tenantB,
            $skuB,
            $locationB,
            'shared-key',
        );

        $blocked = false;

        try {
            $this->reservation(
                $tenantA,
                $skuA,
                $locationA,
                'shared-key',
            );
        } catch (QueryException) {
            $blocked = true;
        }

        $this->assertTrue(
            $blocked,
            'Duplicate tenant reservation idempotency key was accepted.',
        );
    }
}
