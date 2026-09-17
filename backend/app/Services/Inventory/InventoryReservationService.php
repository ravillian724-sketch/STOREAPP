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

    public function synchronizeReference(
        Sku $sku,
        InventoryLocation $location,
        int $quantity,
        string $referenceType,
        string $referenceId,
        DateTimeInterface $expiresAt,
    ): InventoryReservation {
        $tenantId =
            $this->tenantContext->requireId();

        if ($quantity <= 0) {
            throw new InvalidArgumentException(
                'Reservation quantity must be positive.'
            );
        }

        $referenceType =
            trim($referenceType);

        $referenceId =
            trim($referenceId);

        if (
            $referenceType === '' ||
            mb_strlen($referenceType) > 100
        ) {
            throw new InvalidArgumentException(
                'Invalid reservation reference type.'
            );
        }

        if (
            $referenceId === '' ||
            mb_strlen($referenceId) > 120
        ) {
            throw new InvalidArgumentException(
                'Invalid reservation reference id.'
            );
        }

        if (
            (int) $sku->tenant_id !== $tenantId ||
            (int) $location->tenant_id !== $tenantId
        ) {
            throw new LogicException(
                'Reservation references must belong to the active tenant.'
            );
        }

        $normalizedExpiresAt =
            CarbonImmutable::instance(
                $expiresAt
            );

        if (
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
                    ! $sku->track_inventory ||
                    ! $sku->is_active ||
                    ! $location->is_active
                ) {
                    throw new LogicException(
                        'Inventory is not reservable.'
                    );
                }

                $reservations =
                    InventoryReservation::query()
                        ->where(
                            'reference_type',
                            $referenceType,
                        )
                        ->where(
                            'reference_id',
                            $referenceId,
                        )
                        ->where(
                            'status',
                            InventoryReservationStatus::ACTIVE,
                        )
                        ->lockForUpdate()
                        ->get();

                if ($reservations->count() > 1) {
                    throw new LogicException(
                        'Multiple active reservations exist for the same reference.'
                    );
                }

                $existing =
                    $reservations->first();

                if ($existing !== null) {
                    if (
                        (int) $existing->sku_id !==
                            (int) $sku->id ||
                        (int) $existing->location_id !==
                            (int) $location->id
                    ) {
                        throw new LogicException(
                            'Reservation reference is already bound to different inventory.'
                        );
                    }

                    $isCurrentlyCounted =
                        $existing->expires_at === null ||
                        $existing->expires_at->isFuture();

                    $available =
                        $this->availability
                            ->availableToSell(
                                $sku,
                                $location,
                            );

                    $capacity =
                        $available +
                        (
                            $isCurrentlyCounted
                                ? (int) $existing->quantity
                                : 0
                        );

                    if ($quantity > $capacity) {
                        throw new InsufficientAvailableStockException(
                            requestedQuantity: $quantity,
                            availableQuantity: $capacity,
                        );
                    }

                    $existing->quantity =
                        $quantity;

                    $existing->expires_at =
                        $normalizedExpiresAt;

                    $existing->save();

                    return $existing->refresh();
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

                        'idempotency_key' => 'ref-'.
                            bin2hex(
                                random_bytes(24)
                            ),

                        'reference_type' => $referenceType,

                        'reference_id' => $referenceId,

                        'expires_at' => $normalizedExpiresAt,
                    ]);
            }
        );
    }

    public function releaseReference(
        Sku $sku,
        InventoryLocation $location,
        string $referenceType,
        string $referenceId,
    ): ?InventoryReservation {
        $tenantId =
            $this->tenantContext->requireId();

        $referenceType =
            trim($referenceType);

        $referenceId =
            trim($referenceId);

        if (
            $referenceType === '' ||
            $referenceId === ''
        ) {
            throw new InvalidArgumentException(
                'Reservation reference is required.'
            );
        }

        if (
            (int) $sku->tenant_id !== $tenantId ||
            (int) $location->tenant_id !== $tenantId
        ) {
            throw new LogicException(
                'Reservation references must belong to the active tenant.'
            );
        }

        return DB::transaction(
            function () use (
                $sku,
                $location,
                $referenceType,
                $referenceId,
            ): ?InventoryReservation {
                $this->availability
                    ->lockPosition(
                        $sku,
                        $location,
                    );

                $reservations =
                    InventoryReservation::query()
                        ->where(
                            'reference_type',
                            $referenceType,
                        )
                        ->where(
                            'reference_id',
                            $referenceId,
                        )
                        ->where(
                            'status',
                            InventoryReservationStatus::ACTIVE,
                        )
                        ->lockForUpdate()
                        ->get();

                if ($reservations->count() > 1) {
                    throw new LogicException(
                        'Multiple active reservations exist for the same reference.'
                    );
                }

                $reservation =
                    $reservations->first();

                if ($reservation === null) {
                    return null;
                }

                if (
                    (int) $reservation->sku_id !==
                        (int) $sku->id ||
                    (int) $reservation->location_id !==
                        (int) $location->id
                ) {
                    throw new LogicException(
                        'Reservation reference is bound to different inventory.'
                    );
                }

                $reservation->status =
                    InventoryReservationStatus::RELEASED;

                $reservation->released_at =
                    now();

                $reservation->save();

                return $reservation->refresh();
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
