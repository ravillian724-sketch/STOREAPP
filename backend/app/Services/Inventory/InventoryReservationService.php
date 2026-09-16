<?php

namespace App\Services\Inventory;

use App\Exceptions\Inventory\InsufficientAvailableStockException;
use App\Models\InventoryLocation;
use App\Models\InventoryReservation;
use App\Models\Sku;
use App\Support\Inventory\InventoryReservationStatus;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

final class InventoryReservationService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly InventoryAvailabilityService $availability,
    ) {}

    public function reserve(
        Sku $sku,
        InventoryLocation $location,
        int $quantity,
        string $idempotencyKey,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?DateTimeInterface $expiresAt = null,
    ): InventoryReservation {
        $tenantId =
            $this->tenantContext->requireId();

        if ($quantity <= 0) {
            throw new InvalidArgumentException(
                'Reservation quantity must be positive.'
            );
        }

        $idempotencyKey =
            trim($idempotencyKey);

        if (
            $idempotencyKey === '' ||
            mb_strlen($idempotencyKey) > 120
        ) {
            throw new InvalidArgumentException(
                'Invalid reservation idempotency key.'
            );
        }

        if (
            (int) $sku->tenant_id !==
                $tenantId ||
            (int) $location->tenant_id !==
                $tenantId
        ) {
            throw new LogicException(
                'Reservation references must belong to the active tenant.'
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

        if (
            $referenceType !== null &&
            mb_strlen($referenceType) > 100
        ) {
            throw new InvalidArgumentException(
                'Invalid reservation reference type.'
            );
        }

        if (
            $referenceId !== null &&
            mb_strlen($referenceId) > 120
        ) {
            throw new InvalidArgumentException(
                'Invalid reservation reference id.'
            );
        }

        $normalizedExpiresAt =
            $expiresAt === null
                ? null
                : CarbonImmutable::instance(
                    $expiresAt
                );

        if (
            $normalizedExpiresAt !== null &&
            $normalizedExpiresAt->getTimestamp()
                <= now()->getTimestamp()
        ) {
            throw new InvalidArgumentException(
                'Reservation expiration must be in the future.'
            );
        }

        return DB::transaction(
            function () use (
                $sku,
                $location,
                $quantity,
                $idempotencyKey,
                $referenceType,
                $referenceId,
                $normalizedExpiresAt,
            ): InventoryReservation {
                $this->availability
                    ->lockPosition(
                        $sku,
                        $location,
                    );

                $sku->refresh();
                $location->refresh();

                if (
                    ! $sku->track_inventory
                ) {
                    throw new LogicException(
                        'Cannot reserve an untracked SKU.'
                    );
                }

                if (
                    ! $sku->is_active ||
                    ! $location->is_active
                ) {
                    throw new LogicException(
                        'Cannot reserve inactive inventory.'
                    );
                }

                $existing =
                    InventoryReservation::query()
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
                        $quantity,
                        $referenceType,
                        $referenceId,
                        $normalizedExpiresAt,
                    );
                }

                $available =
                    $this->availability
                        ->availableToSell(
                            $sku,
                            $location,
                        );

                if ($quantity > $available) {
                    throw new InsufficientAvailableStockException(
                        requestedQuantity: $quantity,
                        availableQuantity: $available,
                    );
                }

                return InventoryReservation::query()
                    ->create([
                        'sku_id' => $sku->id,

                        'location_id' => $location->id,

                        'quantity' => $quantity,

                        'status' => InventoryReservationStatus::ACTIVE,

                        'idempotency_key' => $idempotencyKey,

                        'reference_type' => $referenceType,

                        'reference_id' => $referenceId,

                        'expires_at' => $normalizedExpiresAt,
                    ]);
            }
        );
    }

    private function resolveReplay(
        InventoryReservation $reservation,
        Sku $sku,
        InventoryLocation $location,
        int $quantity,
        ?string $referenceType,
        ?string $referenceId,
        ?CarbonImmutable $expiresAt,
    ): InventoryReservation {
        $matches =
            (int) $reservation->sku_id ===
                (int) $sku->id &&
            (int) $reservation->location_id ===
                (int) $location->id &&
            (int) $reservation->quantity ===
                $quantity &&
            $reservation->reference_type ===
                $referenceType &&
            $reservation->reference_id ===
                $referenceId &&
            $reservation->expires_at
                ?->getTimestamp() ===
                $expiresAt?->getTimestamp();

        if (! $matches) {
            throw new LogicException(
                'Reservation idempotency key was reused with different data.'
            );
        }

        return $reservation;
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
