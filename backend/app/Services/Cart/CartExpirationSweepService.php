<?php

namespace App\Services\Cart;

use App\Models\Cart;
use App\Models\Tenant;
use App\Support\Cart\CartStatus;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CartExpirationSweepService
{
    public const DEFAULT_BATCH_SIZE = 100;

    public const MAX_BATCH_SIZE = 1000;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CartLifecycleService $lifecycle,
    ) {}

    public function sweep(
        int $batchSize = self::DEFAULT_BATCH_SIZE,
    ): int {
        if (
            $batchSize < 1 ||
            $batchSize > self::MAX_BATCH_SIZE
        ) {
            throw new InvalidArgumentException(
                'Cart expiration batch size is outside the allowed range.'
            );
        }

        $expiredCount = 0;

        /*
         * Tenant itself is the global partition catalog.
         *
         * Never bypass Cart tenant scopes or PostgreSQL RLS
         * to perform a cross-tenant cleanup query.
         */
        Tenant::query()
            ->select('id')
            ->orderBy('id')
            ->chunkById(
                100,
                function ($tenants) use (
                    $batchSize,
                    &$expiredCount,
                ): void {
                    foreach ($tenants as $tenant) {
                        $expiredCount +=
                            $this->sweepTenant(
                                (int) $tenant->id,
                                $batchSize,
                            );
                    }
                },
            );

        return $expiredCount;
    }

    private function sweepTenant(
        int $tenantId,
        int $batchSize,
    ): int {
        $expiredCount = 0;

        while (true) {
            /*
             * Discovery itself requires a transaction on
             * PostgreSQL because the RLS tenant setting is
             * transaction-local.
             */
            $cartIds =
                DB::transaction(
                    function () use (
                        $tenantId,
                        $batchSize,
                    ): array {
                        $this->tenantContext->set(
                            $tenantId
                        );

                        try {
                            $cutoff =
                                CarbonImmutable::now();

                            return Cart::query()
                                ->where(
                                    'status',
                                    CartStatus::ACTIVE,
                                )
                                ->whereNotNull(
                                    'expires_at'
                                )
                                ->where(
                                    'expires_at',
                                    '<=',
                                    $cutoff,
                                )
                                ->orderBy('id')
                                ->limit(
                                    $batchSize
                                )
                                ->pluck('id')
                                ->map(
                                    fn ($id): int => (int) $id
                                )
                                ->all();
                        } finally {
                            $this->tenantContext
                                ->clear();
                        }
                    }
                );

            if ($cartIds === []) {
                break;
            }

            /*
             * One transaction per cart intentionally keeps
             * lock duration bounded.
             *
             * A large tenant must not hold hundreds of cart
             * and inventory-position locks until the whole
             * tenant batch completes.
             */
            foreach ($cartIds as $cartId) {
                if (
                    $this->expireOne(
                        $tenantId,
                        $cartId,
                    )
                ) {
                    $expiredCount++;
                }
            }
        }

        return $expiredCount;
    }

    private function expireOne(
        int $tenantId,
        int $cartId,
    ): bool {
        return DB::transaction(
            function () use (
                $tenantId,
                $cartId,
            ): bool {
                $this->tenantContext->set(
                    $tenantId
                );

                try {
                    $cart =
                        Cart::query()
                            ->whereKey(
                                $cartId
                            )
                            ->first();

                    /*
                     * Another process may have converted,
                     * abandoned, or already expired it after
                     * discovery. That is a valid race.
                     */
                    if (
                        $cart === null ||
                        $cart->status !==
                            CartStatus::ACTIVE
                    ) {
                        return false;
                    }

                    $result =
                        $this->lifecycle
                            ->expireIfDue(
                                $cart
                            );

                    return
                        $result->status ===
                        CartStatus::EXPIRED;
                } finally {
                    $this->tenantContext
                        ->clear();
                }
            }
        );
    }
}
