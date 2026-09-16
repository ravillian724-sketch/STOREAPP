<?php

namespace App\Services\Inventory;

use App\Models\InventoryLocation;
use App\Models\InventoryPosition;
use App\Models\InventoryReservation;
use App\Models\Sku;
use App\Models\StockLedgerEntry;
use App\Support\Inventory\InventoryReservationStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

final class InventoryAvailabilityService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @return array{Sku, InventoryLocation}
     */
    public function lockPosition(
        Sku $sku,
        InventoryLocation $location,
    ): InventoryPosition {
        $this->assertReferences(
            $sku,
            $location,
        );

        if (
            DB::connection()->transactionLevel()
            < 1
        ) {
            throw new LogicException(
                'Inventory position lock requires an active transaction.'
            );
        }

        $tenantId =
            $this->tenantContext->requireId();

        $now = now();

        DB::table(
            'inventory_positions'
        )->insertOrIgnore([
            'tenant_id' => $tenantId,

            'sku_id' => $sku->id,

            'location_id' => $location->id,

            'created_at' => $now,

            'updated_at' => $now,
        ]);

        $position =
            InventoryPosition::query()
                ->where(
                    'sku_id',
                    $sku->id,
                )
                ->where(
                    'location_id',
                    $location->id,
                )
                ->lockForUpdate()
                ->first();

        if ($position === null) {
            throw new LogicException(
                'Inventory position no longer exists.'
            );
        }

        return $position;
    }

    public function onHand(
        Sku $sku,
        InventoryLocation $location,
    ): int {
        $this->assertReferences(
            $sku,
            $location,
        );

        return (int) StockLedgerEntry::query()
            ->where(
                'sku_id',
                $sku->id,
            )
            ->where(
                'location_id',
                $location->id,
            )
            ->sum(
                'quantity_delta'
            );
    }

    public function reserved(
        Sku $sku,
        InventoryLocation $location,
    ): int {
        $this->assertReferences(
            $sku,
            $location,
        );

        $now = now();

        return (int) InventoryReservation::query()
            ->where(
                'sku_id',
                $sku->id,
            )
            ->where(
                'location_id',
                $location->id,
            )
            ->where(
                'status',
                InventoryReservationStatus::ACTIVE,
            )
            ->where(
                function ($query) use ($now): void {
                    $query
                        ->whereNull(
                            'expires_at'
                        )
                        ->orWhere(
                            'expires_at',
                            '>',
                            $now,
                        );
                }
            )
            ->sum(
                'quantity'
            );
    }

    public function availableToSell(
        Sku $sku,
        InventoryLocation $location,
    ): int {
        return $this->onHand(
            $sku,
            $location,
        ) - $this->reserved(
            $sku,
            $location,
        );
    }

    private function assertReferences(
        Sku $sku,
        InventoryLocation $location,
    ): void {
        $tenantId =
            $this->tenantContext->requireId();

        if (
            (int) $sku->tenant_id !==
                $tenantId ||
            (int) $location->tenant_id !==
                $tenantId
        ) {
            throw new LogicException(
                'Inventory references must belong to the active tenant.'
            );
        }
    }
}
