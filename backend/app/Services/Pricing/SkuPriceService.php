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
            $this->assertSkuOwnership(
                $sku
            );

        [
            $from,
            $until,
        ] = $this->normalizePriceRequest(
            $amountMinor,
            $taxRateBps,
            $effectiveFrom,
            $effectiveUntil,
        );

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
                $lockedSku =
                    $this->lockSku(
                        $sku,
                        $tenantId,
                    );

                $currency =
                    $this->tenantCurrency(
                        $tenantId
                    );

                $this->assertNoOverlap(
                    $lockedSku,
                    $currency,
                    $from,
                    $until,
                );

                return $this->createPrice(
                    $lockedSku,
                    $currency,
                    $amountMinor,
                    $taxRateBps,
                    $taxInclusive,
                    $from,
                    $until,
                );
            }
        );
    }

    /**
     * Atomically closes the price covering $effectiveFrom
     * and creates its replacement.
     *
     * Historical monetary values are never rewritten.
     * Only the previous validity boundary may be shortened.
     */
    public function supersede(
        Sku $sku,
        int $amountMinor,
        int $taxRateBps,
        bool $taxInclusive,
        DateTimeInterface $effectiveFrom,
        ?DateTimeInterface $effectiveUntil = null,
    ): SkuPrice {
        $tenantId =
            $this->assertSkuOwnership(
                $sku
            );

        [
            $from,
            $until,
        ] = $this->normalizePriceRequest(
            $amountMinor,
            $taxRateBps,
            $effectiveFrom,
            $effectiveUntil,
        );

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
                 * Same serialization anchor used by:
                 *
                 * - schedule()
                 * - CartQuoteService
                 *
                 * Therefore a quote cannot race a price
                 * replacement for the same SKU.
                 */
                $lockedSku =
                    $this->lockSku(
                        $sku,
                        $tenantId,
                    );

                $currency =
                    $this->tenantCurrency(
                        $tenantId
                    );

                /*
                 * Find the active price whose half-open
                 * validity window contains $from.
                 *
                 * effective_from is deliberately strict.
                 * A replacement at the exact start of an
                 * existing price would create a zero-length
                 * historical version and is rejected by
                 * the later overlap proof instead.
                 */
                $current =
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
                            'effective_from',
                            '<',
                            $from,
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
                        ->orderByDesc(
                            'effective_from'
                        )
                        ->lockForUpdate()
                        ->first();

                if ($current !== null) {
                    /*
                     * Preserve the historical price row.
                     *
                     * Never rewrite amount/tax/public_id.
                     * Only close its validity window at the
                     * exact boundary where the replacement
                     * becomes effective.
                     */
                    $current->effective_until =
                        $from;

                    $current->save();
                }

                /*
                 * Run overlap validation AFTER shortening
                 * the current window.
                 *
                 * If a future scheduled price still
                 * conflicts, this throws and the entire
                 * transaction restores the original
                 * current-price boundary automatically.
                 */
                $this->assertNoOverlap(
                    $lockedSku,
                    $currency,
                    $from,
                    $until,
                );

                return $this->createPrice(
                    $lockedSku,
                    $currency,
                    $amountMinor,
                    $taxRateBps,
                    $taxInclusive,
                    $from,
                    $until,
                );
            }
        );
    }

    public function resolve(
        Sku $sku,
        DateTimeInterface $at,
    ): ?SkuPrice {
        $tenantId =
            $this->assertSkuOwnership(
                $sku
            );

        $currency =
            $this->tenantCurrency(
                $tenantId
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

    private function assertSkuOwnership(
        Sku $sku,
    ): int {
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

        return $tenantId;
    }

    /**
     * @return array{
     *     0: CarbonImmutable,
     *     1: CarbonImmutable|null
     * }
     */
    private function normalizePriceRequest(
        int $amountMinor,
        int $taxRateBps,
        DateTimeInterface $effectiveFrom,
        ?DateTimeInterface $effectiveUntil,
    ): array {
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
            ! $until->greaterThan(
                $from
            )
        ) {
            throw new InvalidArgumentException(
                'SKU price expiration must be after its effective start.'
            );
        }

        return [
            $from,
            $until,
        ];
    }

    private function lockSku(
        Sku $sku,
        int $tenantId,
    ): Sku {
        $lockedSku =
            Sku::query()
                ->whereKey(
                    $sku->id
                )
                ->lockForUpdate()
                ->first();

        if (
            $lockedSku === null ||
            (int) $lockedSku->tenant_id !==
                $tenantId
        ) {
            throw new LogicException(
                'SKU is not accessible in the active tenant.'
            );
        }

        return $lockedSku;
    }

    private function tenantCurrency(
        int $tenantId,
    ): string {
        $tenant =
            Tenant::query()
                ->whereKey(
                    $tenantId
                )
                ->firstOrFail();

        $currency =
            strtoupper(
                trim(
                    (string)
                    $tenant->currency_code
                )
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

        return $currency;
    }

    private function assertNoOverlap(
        Sku $sku,
        string $currency,
        CarbonImmutable $from,
        ?CarbonImmutable $until,
    ): void {
        $overlap =
            SkuPrice::query()
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
    }

    private function createPrice(
        Sku $sku,
        string $currency,
        int $amountMinor,
        int $taxRateBps,
        bool $taxInclusive,
        CarbonImmutable $from,
        ?CarbonImmutable $until,
    ): SkuPrice {
        return SkuPrice::query()
            ->create([
                'sku_id' => $sku->id,

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
}
