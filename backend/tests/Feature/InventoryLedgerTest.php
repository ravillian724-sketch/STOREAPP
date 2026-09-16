<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\Sku;
use App\Models\StockLedgerEntry;
use App\Models\Tenant;
use App\Services\Inventory\StockLedgerService;
use App\Support\Inventory\InventoryMovementType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class InventoryLedgerTest extends TestCase
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

        $context->set($tenant->id);

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

    private function branch(
        Tenant $tenant,
        string $code,
    ): Branch {
        return $this->inTenant(
            $tenant,
            fn (): Branch => Branch::query()->create([
                'code' => $code,
                'name_ar' => $code,
                'name_en' => $code,
                'is_active' => true,
            ]),
        );
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
        ?Branch $branch,
        string $code,
    ): InventoryLocation {
        return $this->inTenant(
            $tenant,
            fn (): InventoryLocation => InventoryLocation::query()
                ->create([
                    'branch_id' => $branch?->id,

                    'code' => $code,

                    'name_ar' => $code,

                    'name_en' => $code,

                    'type' => 'stock',

                    'is_active' => true,
                ]),
        );
    }

    public function test_inventory_models_fail_closed_without_context(): void
    {
        $tenant = $this->tenant(
            'Tenant A'
        );

        $branch = $this->branch(
            $tenant,
            'A01',
        );

        $sku = $this->sku(
            $tenant,
            'SKU-A',
        );

        $location = $this->location(
            $tenant,
            $branch,
            'MAIN',
        );

        $this->inTenant(
            $tenant,
            function () use (
                $sku,
                $location,
            ): void {
                app(
                    StockLedgerService::class
                )->post(
                    $sku,
                    $location,
                    10,
                    InventoryMovementType::OPENING,
                    'opening-001',
                );
            },
        );

        $this->assertSame(
            0,
            InventoryLocation::query()->count(),
        );

        $this->assertSame(
            0,
            StockLedgerEntry::query()->count(),
        );
    }

    public function test_location_cannot_reference_foreign_tenant_branch(): void
    {
        $tenantA = $this->tenant(
            'Tenant A'
        );

        $tenantB = $this->tenant(
            'Tenant B'
        );

        $foreignBranch = $this->branch(
            $tenantA,
            'A01',
        );

        $blocked = false;

        $this->inTenant(
            $tenantB,
            function () use (
                $foreignBranch,
                &$blocked,
            ): void {
                try {
                    DB::transaction(
                        function () use (
                            $foreignBranch
                        ): void {
                            InventoryLocation::query()
                                ->create([
                                    'branch_id' => $foreignBranch->id,

                                    'code' => 'ILLEGAL',

                                    'name_ar' => 'ILLEGAL',

                                    'name_en' => 'ILLEGAL',

                                    'type' => 'stock',

                                    'is_active' => true,
                                ]);
                        }
                    );
                } catch (QueryException) {
                    $blocked = true;
                }
            },
        );

        $this->assertTrue($blocked);
    }

    public function test_branch_with_inventory_location_cannot_be_hard_deleted(): void
    {
        $tenant = $this->tenant(
            'Tenant A'
        );

        $branch = $this->branch(
            $tenant,
            'A01',
        );

        $this->location(
            $tenant,
            $branch,
            'MAIN',
        );

        $blocked = false;

        $this->inTenant(
            $tenant,
            function () use (
                $branch,
                &$blocked,
            ): void {
                try {
                    DB::transaction(
                        fn () => $branch->delete()
                    );
                } catch (QueryException) {
                    $blocked = true;
                }
            },
        );

        $this->assertTrue(
            $blocked
        );
    }

    public function test_ledger_is_idempotent_and_balance_is_derived(): void
    {
        $tenant = $this->tenant(
            'Tenant A'
        );

        $branch = $this->branch(
            $tenant,
            'A01',
        );

        $sku = $this->sku(
            $tenant,
            'SKU-A',
        );

        $location = $this->location(
            $tenant,
            $branch,
            'MAIN',
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

                $first = $ledger->post(
                    $sku,
                    $location,
                    100,
                    InventoryMovementType::OPENING,
                    'opening-001',
                );

                $replay = $ledger->post(
                    $sku,
                    $location,
                    100,
                    InventoryMovementType::OPENING,
                    'opening-001',
                );

                $ledger->post(
                    $sku,
                    $location,
                    -15,
                    InventoryMovementType::SHIPMENT,
                    'shipment-001',
                );

                $this->assertSame(
                    $first->id,
                    $replay->id,
                );

                $this->assertSame(
                    2,
                    StockLedgerEntry::query()
                        ->count(),
                );

                $this->assertSame(
                    85,
                    $ledger->onHand(
                        $sku,
                        $location,
                    ),
                );
            },
        );
    }

    public function test_idempotency_key_cannot_be_reused_with_different_data(): void
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
            null,
            'MAIN',
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

                $ledger->post(
                    $sku,
                    $location,
                    10,
                    InventoryMovementType::RECEIPT,
                    'receipt-001',
                );

                $this->expectException(
                    LogicException::class
                );

                $ledger->post(
                    $sku,
                    $location,
                    11,
                    InventoryMovementType::RECEIPT,
                    'receipt-001',
                );
            },
        );
    }

    public function test_movement_direction_is_enforced(): void
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
            null,
            'MAIN',
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

                $blocked = 0;

                foreach (
                    [
                        [
                            InventoryMovementType::SHIPMENT,
                            5,
                            'bad-shipment',
                        ],
                        [
                            InventoryMovementType::RECEIPT,
                            -5,
                            'bad-receipt',
                        ],
                    ] as [
                        $movementType,
                        $quantityDelta,
                        $idempotencyKey,
                    ]
                ) {
                    try {
                        $ledger->post(
                            $sku,
                            $location,
                            $quantityDelta,
                            $movementType,
                            $idempotencyKey,
                        );
                    } catch (
                        InvalidArgumentException
                    ) {
                        $blocked++;
                    }
                }

                $this->assertSame(
                    2,
                    $blocked,
                );

                $this->assertSame(
                    0,
                    StockLedgerEntry::query()
                        ->count(),
                );
            },
        );
    }

    public function test_ledger_rejects_cross_tenant_references(): void
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
            null,
            'B-MAIN',
        );

        $this->expectException(
            LogicException::class
        );

        $this->inTenant(
            $tenantA,
            function () use (
                $skuA,
                $locationB,
            ): void {
                app(
                    StockLedgerService::class
                )->post(
                    $skuA,
                    $locationB,
                    1,
                    InventoryMovementType::RECEIPT,
                    'illegal-001',
                );
            },
        );
    }

    public function test_ledger_entries_are_immutable_through_eloquent(): void
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
            null,
            'MAIN',
        );

        $this->inTenant(
            $tenant,
            function () use (
                $sku,
                $location,
            ): void {
                $entry = app(
                    StockLedgerService::class
                )->post(
                    $sku,
                    $location,
                    10,
                    InventoryMovementType::OPENING,
                    'opening-001',
                );

                $this->expectException(
                    LogicException::class
                );

                $entry->update([
                    'quantity_delta' => 999,
                ]);
            },
        );
    }

    public function test_postgres_ledger_is_rls_protected_and_database_immutable(): void
    {
        if (
            DB::connection()->getDriverName()
            !== 'pgsql'
        ) {
            $this->markTestSkipped(
                'PostgreSQL-specific ledger security test.'
            );
        }

        $tenant = $this->tenant(
            'Tenant A'
        );

        $sku = $this->sku(
            $tenant,
            'SKU-A',
        );

        $location = $this->location(
            $tenant,
            null,
            'MAIN',
        );

        $entry = $this->inTenant(
            $tenant,
            fn (): StockLedgerEntry => app(
                StockLedgerService::class
            )->post(
                $sku,
                $location,
                10,
                InventoryMovementType::OPENING,
                'opening-001',
            ),
        );

        $this->assertSame(
            0,
            DB::table(
                'stock_ledger_entries'
            )->count(),
        );

        $this->inTenant(
            $tenant,
            function () use ($entry): void {
                $this->assertSame(
                    1,
                    DB::table(
                        'stock_ledger_entries'
                    )->count(),
                );

                $updateBlocked = false;

                try {
                    DB::transaction(
                        fn () => DB::table(
                            'stock_ledger_entries'
                        )
                            ->where(
                                'id',
                                $entry->id,
                            )
                            ->update([
                                'quantity_delta' => 999,
                            ])
                    );
                } catch (QueryException) {
                    $updateBlocked = true;
                }

                $this->assertTrue(
                    $updateBlocked
                );

                $deleteBlocked = false;

                try {
                    DB::transaction(
                        fn () => DB::table(
                            'stock_ledger_entries'
                        )
                            ->where(
                                'id',
                                $entry->id,
                            )
                            ->delete()
                    );
                } catch (QueryException) {
                    $deleteBlocked = true;
                }

                $this->assertTrue(
                    $deleteBlocked
                );
            },
        );
    }
}
