<?php

namespace App\Services\Pricing;

use App\Models\Sku;
use App\Models\SkuPrice;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class SkuPriceService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function schedule(
        Sku $sku,
        int $amountMinor,
        int $taxRateBps,
        bool $taxInclusive,
        DateTimeInterface $effectiveFrom,
        ?DateTimeInterface $effectiveUntil = null,
    ): SkuPrice {
        $tenantId =
            $this->tenantContext->requireId();

        if (
            (int) $sku->tenant_id !==
            $tenantId
        ) {
            throw new LogicException(
                'SKU price must belong to the active tenant.'
            );
        }

        if ($amountMinor <= 0) {
            throw new InvalidArgumentException(
                'SKU price amount must be positive.'
            );
        }

        if (
            $taxRateBps < 0 ||
            $taxRateBps > 10000
        ) {
            throw new InvalidArgumentException(
                'SKU tax rate must be between 0 and 10000 basis points.'
            );
        }

        $from =
            CarbonImmutable::instance(
                $effectiveFrom
            );

        $until =
            $effectiveUntil === null
                ? null
                : CarbonImmutable::instance(
                    $effectiveUntil
                );

        if (
            $until !== null &&
            ! $until->greaterThan($from)
        ) {
            throw new InvalidArgumentException(
                'SKU price expiration must be after its effective start.'
            );
        }

        return DB::transaction(
            function () use (
                $sku,
                $tenantId,
                $amountMinor,
                $taxRateBps,
                $taxInclusive,
                $from,
                $until,
            ): SkuPrice {
                /*
                 * This lock is the serialization anchor
                 * for application-level price scheduling.
                 */
                $lockedSku =
                    Sku::query()
                        ->whereKey(
                            $sku->id
                        )
                        ->lockForUpdate()
                        ->first();

                if ($lockedSku === null) {
                    throw new LogicException(
                        'SKU is not accessible in the active tenant.'
                    );
                }

                $tenant =
                    Tenant::query()
                        ->whereKey(
                            $tenantId
                        )
                        ->firstOrFail();

                $currency =
                    strtoupper(
                        (string)
                        $tenant->currency_code
                    );

                if (
                    preg_match(
                        '/^[A-Z]{3}$/',
                        $currency,
                    ) !== 1
                ) {
                    throw new LogicException(
                        'Tenant currency code is invalid.'
                    );
                }

                /*
                 * Keep the same invariant on SQLite and
                 * PostgreSQL through the domain service.
                 *
                 * PostgreSQL additionally enforces it
                 * with an exclusion constraint to close
                 * concurrent/raw-write races.
                 */
                $overlap =
                    SkuPrice::query()
                        ->where(
                            'sku_id',
                            $lockedSku->id,
                        )
                        ->where(
                            'currency_code',
                            $currency,
                        )
                        ->where(
                            'is_active',
                            true,
                        )
                        ->where(
                            function ($query) use (
                                $from
                            ): void {
                                $query
                                    ->whereNull(
                                        'effective_until'
                                    )
                                    ->orWhere(
                                        'effective_until',
                                        '>',
                                        $from,
                                    );
                            }
                        )
                        ->when(
                            $until !== null,
                            function (
                                $query
                            ) use ($until): void {
                                $query->where(
                                    'effective_from',
                                    '<',
                                    $until,
                                );
                            }
                        )
                        ->exists();

                if ($overlap) {
                    throw new LogicException(
                        'SKU price window overlaps an active price.'
                    );
                }

                return SkuPrice::query()
                    ->create([
                        'sku_id' => $lockedSku->id,

                        'public_id' => (string) Str::uuid(),

                        'currency_code' => $currency,

                        'amount_minor' => $amountMinor,

                        'tax_rate_bps' => $taxRateBps,

                        'tax_inclusive' => $taxInclusive,

                        'effective_from' => $from,

                        'effective_until' => $until,

                        'is_active' => true,
                    ]);
            }
        );
    }

    public function resolve(
        Sku $sku,
        DateTimeInterface $at,
    ): ?SkuPrice {
        $tenantId =
            $this->tenantContext->requireId();

        if (
            (int) $sku->tenant_id !==
            $tenantId
        ) {
            throw new LogicException(
                'SKU price must belong to the active tenant.'
            );
        }

        $tenant =
            Tenant::query()
                ->whereKey(
                    $tenantId
                )
                ->firstOrFail();

        $currency =
            strtoupper(
                (string)
                $tenant->currency_code
            );

        $time =
            CarbonImmutable::instance(
                $at
            );

        return SkuPrice::query()
            ->where(
                'sku_id',
                $sku->id,
            )
            ->where(
                'currency_code',
                $currency,
            )
            ->where(
                'is_active',
                true,
            )
            ->where(
                'effective_from',
                '<=',
                $time,
            )
            ->where(
                function ($query) use (
                    $time
                ): void {
                    $query
                        ->whereNull(
                            'effective_until'
                        )
                        ->orWhere(
                            'effective_until',
                            '>',
                            $time,
                        );
                }
            )
            ->orderByDesc(
                'effective_from'
            )
            ->first();
    }
}
