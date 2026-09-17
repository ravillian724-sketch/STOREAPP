<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sku;
use App\Models\SkuPrice;
use App\Models\Tenant;
use App\Services\Pricing\SkuPriceService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class PricingLifecycleTest extends TestCase
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
                            'name_ar' => 'منتج '.$code,

                            'name_en' => 'Product '.$code,

                            'is_active' => true,
                        ]);

                return Sku::query()
                    ->create([
                        'product_id' => $product->id,

                        'code' => $code,

                        'barcode' => 'BAR-'.$code,

                        'name_ar' => 'عبوة',

                        'name_en' => 'Pack',

                        'track_inventory' => true,

                        'is_active' => true,
                    ]);
            },
        );
    }

    public function test_supersede_atomically_closes_open_ended_price_and_preserves_history(): void
    {
        $tenant =
            $this->tenant();

        $sku =
            $this->sku(
                $tenant
            );

        $oldStart =
            CarbonImmutable::parse(
                '2026-09-17 08:00:00'
            );

        $changeAt =
            CarbonImmutable::parse(
                '2026-09-17 12:00:00'
            );

        $this->inTenant(
            $tenant,
            function () use (
                $sku,
                $oldStart,
                $changeAt,
            ): void {
                $service =
                    app(
                        SkuPriceService::class
                    );

                $old =
                    $service->schedule(
                        $sku,
                        1000,
                        1500,
                        true,
                        $oldStart,
                        null,
                    );

                $replacement =
                    $service->supersede(
                        $sku,
                        1200,
                        1500,
                        true,
                        $changeAt,
                        null,
                    );

                $old->refresh();

                /*
                 * Historical monetary identity remains
                 * unchanged. Only the validity end moves.
                 */
                $this->assertSame(
                    1000,
                    $old->amount_minor,
                );

                $this->assertSame(
                    1500,
                    $old->tax_rate_bps,
                );

                $this->assertSame(
                    $changeAt->getTimestamp(),
                    $old
                        ->effective_until
                        ->getTimestamp(),
                );

                $this->assertSame(
                    1200,
                    $replacement
                        ->amount_minor,
                );

                $before =
                    $service->resolve(
                        $sku,
                        $changeAt
                            ->subSecond(),
                    );

                $atBoundary =
                    $service->resolve(
                        $sku,
                        $changeAt,
                    );

                $this->assertNotNull(
                    $before
                );

                $this->assertNotNull(
                    $atBoundary
                );

                $this->assertSame(
                    $old->id,
                    $before->id,
                );

                $this->assertSame(
                    $replacement->id,
                    $atBoundary->id,
                );
            },
        );
    }

    public function test_supersede_rolls_back_old_boundary_when_future_price_conflicts(): void
    {
        $tenant =
            $this->tenant();

        $sku =
            $this->sku(
                $tenant
            );

        $oldStart =
            CarbonImmutable::parse(
                '2026-09-17 08:00:00'
            );

        $futureStart =
            CarbonImmutable::parse(
                '2026-09-17 16:00:00'
            );

        $replaceAt =
            CarbonImmutable::parse(
                '2026-09-17 12:00:00'
            );

        $this->inTenant(
            $tenant,
            function () use (
                $sku,
                $oldStart,
                $futureStart,
                $replaceAt,
            ): void {
                $service =
                    app(
                        SkuPriceService::class
                    );

                $old =
                    $service->schedule(
                        $sku,
                        1000,
                        1500,
                        true,
                        $oldStart,
                        $futureStart,
                    );

                $future =
                    $service->schedule(
                        $sku,
                        1400,
                        1500,
                        true,
                        $futureStart,
                        null,
                    );

                try {
                    /*
                     * Open-ended replacement would collide
                     * with the already scheduled future
                     * version.
                     */
                    $service->supersede(
                        $sku,
                        1200,
                        1500,
                        true,
                        $replaceAt,
                        null,
                    );

                    $this->fail(
                        'Expected overlapping replacement to fail.'
                    );
                } catch (
                    LogicException
                ) {
                    $this->addToAssertionCount(
                        1
                    );
                }

                $old->refresh();
                $future->refresh();

                /*
                 * The failed replacement must roll back
                 * the temporary shortening of the old
                 * price window.
                 */
                $this->assertSame(
                    $futureStart
                        ->getTimestamp(),
                    $old
                        ->effective_until
                        ->getTimestamp(),
                );

                $resolvedOld =
                    $service->resolve(
                        $sku,
                        $replaceAt,
                    );

                $resolvedFuture =
                    $service->resolve(
                        $sku,
                        $futureStart,
                    );

                $this->assertNotNull(
                    $resolvedOld
                );

                $this->assertNotNull(
                    $resolvedFuture
                );

                $this->assertSame(
                    $old->id,
                    $resolvedOld->id,
                );

                $this->assertSame(
                    $future->id,
                    $resolvedFuture->id,
                );

                $this->assertSame(
                    2,
                    SkuPrice::query()
                        ->where(
                            'sku_id',
                            $sku->id,
                        )
                        ->count(),
                );
            },
        );
    }

    public function test_supersede_can_fill_free_window_before_existing_future_price(): void
    {
        $tenant =
            $this->tenant();

        $sku =
            $this->sku(
                $tenant
            );

        $oldStart =
            CarbonImmutable::parse(
                '2026-09-17 08:00:00'
            );

        $replaceAt =
            CarbonImmutable::parse(
                '2026-09-17 12:00:00'
            );

        $futureStart =
            CarbonImmutable::parse(
                '2026-09-17 16:00:00'
            );

        $this->inTenant(
            $tenant,
            function () use (
                $sku,
                $oldStart,
                $replaceAt,
                $futureStart,
            ): void {
                $service =
                    app(
                        SkuPriceService::class
                    );

                $old =
                    $service->schedule(
                        $sku,
                        1000,
                        1500,
                        true,
                        $oldStart,
                        $futureStart,
                    );

                $future =
                    $service->schedule(
                        $sku,
                        1400,
                        1500,
                        true,
                        $futureStart,
                        null,
                    );

                $middle =
                    $service->supersede(
                        $sku,
                        1200,
                        1500,
                        true,
                        $replaceAt,
                        $futureStart,
                    );

                $old->refresh();

                $this->assertSame(
                    $replaceAt
                        ->getTimestamp(),
                    $old
                        ->effective_until
                        ->getTimestamp(),
                );

                $this->assertSame(
                    $futureStart
                        ->getTimestamp(),
                    $middle
                        ->effective_until
                        ->getTimestamp(),
                );

                $this->assertSame(
                    $old->id,
                    $service->resolve(
                        $sku,
                        $replaceAt
                            ->subSecond(),
                    )->id,
                );

                $this->assertSame(
                    $middle->id,
                    $service->resolve(
                        $sku,
                        $replaceAt,
                    )->id,
                );

                $this->assertSame(
                    $future->id,
                    $service->resolve(
                        $sku,
                        $futureStart,
                    )->id,
                );
            },
        );
    }

    public function test_supersede_at_existing_price_start_is_rejected_without_mutation(): void
    {
        $tenant =
            $this->tenant();

        $sku =
            $this->sku(
                $tenant
            );

        $start =
            CarbonImmutable::parse(
                '2026-09-17 10:00:00'
            );

        $this->inTenant(
            $tenant,
            function () use (
                $sku,
                $start,
            ): void {
                $service =
                    app(
                        SkuPriceService::class
                    );

                $existing =
                    $service->schedule(
                        $sku,
                        1000,
                        1500,
                        true,
                        $start,
                        null,
                    );

                try {
                    $service->supersede(
                        $sku,
                        1200,
                        1500,
                        true,
                        $start,
                        null,
                    );

                    $this->fail(
                        'Expected same-start replacement to fail.'
                    );
                } catch (
                    LogicException
                ) {
                    $this->addToAssertionCount(
                        1
                    );
                }

                $existing->refresh();

                $this->assertNull(
                    $existing
                        ->effective_until
                );

                $this->assertSame(
                    1000,
                    $existing
                        ->amount_minor,
                );

                $this->assertSame(
                    1,
                    SkuPrice::query()
                        ->where(
                            'sku_id',
                            $sku->id,
                        )
                        ->count(),
                );
            },
        );
    }
}
