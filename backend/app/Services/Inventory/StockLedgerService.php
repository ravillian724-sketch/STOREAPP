<?php

namespace App\Services\Inventory;

use App\Exceptions\Inventory\InsufficientAvailableStockException;
use App\Models\InventoryLocation;
use App\Models\Sku;
use App\Models\StockLedgerEntry;
use App\Support\Inventory\InventoryMovementType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

class StockLedgerService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly InventoryAvailabilityService $availability,
    ) {}

    public function post(
        Sku $sku,
        InventoryLocation $location,
        int $quantityDelta,
        string $movementType,
        string $idempotencyKey,
        ?string $referenceType = null,
        ?string $referenceId = null,
    ): StockLedgerEntry {
        $tenantId =
            $this->tenantContext->requireId();

        if ($quantityDelta === 0) {
            throw new InvalidArgumentException(
                'Inventory movement cannot be zero.'
            );
        }

        if (
            ! in_array(
                $movementType,
                InventoryMovementType::all(),
                true,
            )
        ) {
            throw new InvalidArgumentException(
                'Unknown inventory movement type.'
            );
        }

        $this->assertMovementDirection(
            $quantityDelta,
            $movementType,
        );

        $idempotencyKey =
            trim($idempotencyKey);

        if (
            $idempotencyKey === '' ||
            mb_strlen($idempotencyKey) > 120
        ) {
            throw new InvalidArgumentException(
                'Invalid inventory idempotency key.'
            );
        }

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

        if (! $sku->track_inventory) {
            throw new LogicException(
                'Inventory cannot be posted for an untracked SKU.'
            );
        }

        $referenceType =
            $this->normalizeNullable(
                $referenceType
            );

        $referenceId =
            $this->normalizeNullable(
                $referenceId
            );

        try {
            return DB::transaction(
                function () use (
                    $sku,
                    $location,
                    $quantityDelta,
                    $movementType,
                    $idempotencyKey,
                    $referenceType,
                    $referenceId,
                ): StockLedgerEntry {
                    $this->availability
                        ->lockPosition(
                            $sku,
                            $location,
                        );

                    $existing =
                        StockLedgerEntry::query()
                            ->where(
                                'idempotency_key',
                                $idempotencyKey,
                            )
                            ->first();

                    if ($existing !== null) {
                        return $this->resolveReplay(
                            $existing,
                            $sku,
                            $location,
                            $quantityDelta,
                            $movementType,
                            $referenceType,
                            $referenceId,
                        );
                    }

                    if ($quantityDelta < 0) {
                        $available =
                            $this->availability
                                ->availableToSell(
                                    $sku,
                                    $location,
                                );

                        $requested =
                            abs($quantityDelta);

                        if (
                            $requested >
                            $available
                        ) {
                            throw new InsufficientAvailableStockException(
                                requestedQuantity: $requested,
                                availableQuantity: $available,
                            );
                        }
                    }

                    $now = now();

                    return StockLedgerEntry::query()
                        ->create([
                            'sku_id' => $sku->id,

                            'location_id' => $location->id,

                            'movement_type' => $movementType,

                            'quantity_delta' => $quantityDelta,

                            'idempotency_key' => $idempotencyKey,

                            'reference_type' => $referenceType,

                            'reference_id' => $referenceId,

                            'occurred_at' => $now,

                            'created_at' => $now,
                        ]);
                }
            );
        } catch (QueryException $exception) {
            $existing =
                StockLedgerEntry::query()
                    ->where(
                        'idempotency_key',
                        $idempotencyKey,
                    )
                    ->first();

            if ($existing === null) {
                throw $exception;
            }

            return $this->resolveReplay(
                $existing,
                $sku,
                $location,
                $quantityDelta,
                $movementType,
                $referenceType,
                $referenceId,
            );
        }
    }

    public function onHand(
        Sku $sku,
        InventoryLocation $location,
    ): int {
        return $this->availability
            ->onHand(
                $sku,
                $location,
            );
    }

    private function resolveReplay(
        StockLedgerEntry $entry,
        Sku $sku,
        InventoryLocation $location,
        int $quantityDelta,
        string $movementType,
        ?string $referenceType,
        ?string $referenceId,
    ): StockLedgerEntry {
        $matches =
            (int) $entry->sku_id ===
                (int) $sku->id &&
            (int) $entry->location_id ===
                (int) $location->id &&
            (int) $entry->quantity_delta ===
                $quantityDelta &&
            $entry->movement_type ===
                $movementType &&
            $entry->reference_type ===
                $referenceType &&
            $entry->reference_id ===
                $referenceId;

        if (! $matches) {
            throw new LogicException(
                'Inventory idempotency key was reused with different data.'
            );
        }

        return $entry;
    }

    private function assertMovementDirection(
        int $quantityDelta,
        string $movementType,
    ): void {
        $positiveOnly = [
            InventoryMovementType::OPENING,
            InventoryMovementType::RECEIPT,
            InventoryMovementType::CUSTOMER_RETURN,
            InventoryMovementType::TRANSFER_IN,
        ];

        $negativeOnly = [
            InventoryMovementType::SHIPMENT,
            InventoryMovementType::SUPPLIER_RETURN,
            InventoryMovementType::TRANSFER_OUT,
        ];

        if (
            $quantityDelta < 0 &&
            in_array(
                $movementType,
                $positiveOnly,
                true,
            )
        ) {
            throw new InvalidArgumentException(
                'This inventory movement must increase stock.'
            );
        }

        if (
            $quantityDelta > 0 &&
            in_array(
                $movementType,
                $negativeOnly,
                true,
            )
        ) {
            throw new InvalidArgumentException(
                'This inventory movement must decrease stock.'
            );
        }

        // Adjustment intentionally supports
        // both positive and negative quantities.
    }

    private function normalizeNullable(
        ?string $value,
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === ''
            ? null
            : $value;
    }
}
