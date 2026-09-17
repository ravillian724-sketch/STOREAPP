<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sku;
use App\Models\SkuPrice;
use App\Models\Tenant;
use App\Services\Pricing\SkuPriceService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class PricingFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(
        string $name,
        string $currency = 'SAR',
    ): Tenant {
        return Tenant::query()->create([
            'name_ar' => $name,
            'name_en' => $name,
            'country_code' => 'SA',
            'currency_code' => $currency,
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
        string $code,
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

    private function assertInvalid(
        callable $callback,
    ): void {
        try {
            $callback();

            $this->fail(
                'Expected InvalidArgumentException.'
            );
        } catch (
            InvalidArgumentException
        ) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_prices_fail_closed_without_tenant_context(): void
    {
        $tenant =
            $this->tenant('Tenant A');

        $sku =
            $this->sku(
                $tenant,
                'SKU-A',
            );

        $this->expectException(
            RuntimeException::class
        );

        SkuPrice::query()->create([
            'sku_id' => $sku->id,
            'public_id' => fake()->uuid(),

            'currency_code' => 'SAR',

            'amount_minor' => 1000,

            'tax_rate_bps' => 1500,

            'tax_inclusive' => true,

            'effective_from' => now(),

            'effective_until' => null,

            'is_active' => true,
        ]);
    }

    public function test_schedule_creates_trusted_tenant_currency_price(): void
    {
        $tenant =
            $this->tenant(
                'Tenant A',
                'SAR',
            );

        $sku =
            $this->sku(
                $tenant,
                'SKU-A',
            );

        $from =
            CarbonImmutable::parse(
                '2026-09-17 10:00:00'
            );

        $price =
            $this->inTenant(
                $tenant,
                fn (): SkuPrice => app(
                    SkuPriceService::class
                )->schedule(
                    $sku,
                    2575,
                    1500,
                    true,
                    $from,
                    null,
                ),
            );

        $this->assertSame(
            $tenant->id,
            (int) $price->tenant_id,
        );

        $this->assertSame(
            $sku->id,
            (int) $price->sku_id,
        );

        $this->assertSame(
            'SAR',
            $price->currency_code,
        );

        $this->assertSame(
            2575,
            $price->amount_minor,
        );

        $this->assertSame(
            1500,
            $price->tax_rate_bps,
        );

        $this->assertTrue(
            $price->tax_inclusive
        );

        $this->assertTrue(
            $price->is_active
        );
    }

    public function test_foreign_tenant_sku_cannot_be_priced(): void
    {
        $tenantA =
            $this->tenant('Tenant A');

        $tenantB =
            $this->tenant('Tenant B');

        $foreignSku =
            $this->sku(
                $tenantB,
                'SKU-B',
            );

        $this->expectException(
            LogicException::class
        );

        $this->inTenant(
            $tenantA,
            fn () => app(
                SkuPriceService::class
            )->schedule(
                $foreignSku,
                1000,
                1500,
                true,
                now(),
                null,
            ),
        );
    }

    public function test_invalid_price_inputs_are_rejected(): void
    {
        $tenant =
            $this->tenant('Tenant A');

        $sku =
            $this->sku(
                $tenant,
                'SKU-A',
            );

        $from =
            CarbonImmutable::parse(
                '2026-09-17 10:00:00'
            );

        $this->inTenant(
            $tenant,
            function () use (
                $sku,
                $from,
            ): void {
                $service =
                    app(
                        SkuPriceService::class
                    );

                $this->assertInvalid(
                    fn () => $service->schedule(
                        $sku,
                        0,
                        1500,
                        true,
                        $from,
                        null,
                    )
                );

                $this->assertInvalid(
                    fn () => $service->schedule(
                        $sku,
                        1000,
                        10001,
                        true,
                        $from,
                        null,
                    )
                );

                $this->assertInvalid(
                    fn () => $service->schedule(
                        $sku,
                        1000,
                        1500,
                        true,
                        $from,
                        $from,
                    )
                );
            },
        );
    }

    public function test_overlapping_price_windows_are_rejected(): void
    {
        $tenant =
            $this->tenant('Tenant A');

        $sku =
            $this->sku(
                $tenant,
                'SKU-A',
            );

        $from =
            CarbonImmutable::parse(
                '2026-09-17 10:00:00'
            );

        $until =
            $from->addDay();

        $this->inTenant(
            $tenant,
            function () use (
                $sku,
                $from,
                $until,
            ): void {
                $service =
                    app(
                        SkuPriceService::class
                    );

                $service->schedule(
                    $sku,
                    1000,
                    1500,
                    true,
                    $from,
                    $until,
                );

                $this->expectException(
                    LogicException::class
                );

                $service->schedule(
                    $sku,
                    1200,
                    1500,
                    true,
                    $from->addHour(),
                    $until->addHour(),
                );
            },
        );
    }

    public function test_adjacent_windows_are_allowed_and_resolved_by_time(): void
    {
        $tenant =
            $this->tenant('Tenant A');

        $sku =
            $this->sku(
                $tenant,
                'SKU-A',
            );

        $firstStart =
            CarbonImmutable::parse(
                '2026-09-17 10:00:00'
            );

        $secondStart =
            $firstStart->addDay();

        $this->inTenant(
            $tenant,
            function () use (
                $sku,
                $firstStart,
                $secondStart,
            ): void {
                $service =
                    app(
                        SkuPriceService::class
                    );

                $first =
                    $service->schedule(
                        $sku,
                        1000,
                        1500,
                        true,
                        $firstStart,
                        $secondStart,
                    );

                $second =
                    $service->schedule(
                        $sku,
                        1200,
                        1500,
                        true,
                        $secondStart,
                        null,
                    );

                $resolvedFirst =
                    $service->resolve(
                        $sku,
                        $firstStart
                            ->addHour(),
                    );

                $resolvedSecond =
                    $service->resolve(
                        $sku,
                        $secondStart,
                    );

                $this->assertNotNull(
                    $resolvedFirst
                );

                $this->assertNotNull(
                    $resolvedSecond
                );

                $this->assertSame(
                    $first->id,
                    $resolvedFirst->id,
                );

                $this->assertSame(
                    $second->id,
                    $resolvedSecond->id,
                );

                $this->assertSame(
                    1000,
                    $resolvedFirst
                        ->amount_minor,
                );

                $this->assertSame(
                    1200,
                    $resolvedSecond
                        ->amount_minor,
                );
            },
        );
    }

    public function test_database_blocks_cross_tenant_sku_reference(): void
    {
        $tenantA =
            $this->tenant('Tenant A');

        $tenantB =
            $this->tenant('Tenant B');

        $foreignSku =
            $this->sku(
                $tenantB,
                'SKU-B',
            );

        $this->expectException(
            QueryException::class
        );

        $this->inTenant(
            $tenantA,
            fn () => SkuPrice::query()
                ->create([
                    'sku_id' => $foreignSku->id,

                    'public_id' => fake()->uuid(),

                    'currency_code' => 'SAR',

                    'amount_minor' => 1000,

                    'tax_rate_bps' => 1500,

                    'tax_inclusive' => true,

                    'effective_from' => now(),

                    'effective_until' => null,

                    'is_active' => true,
                ]),
        );
    }
}
