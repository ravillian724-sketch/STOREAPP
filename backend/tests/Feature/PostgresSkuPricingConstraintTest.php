<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sku;
use App\Models\Tenant;
use App\Services\Pricing\SkuPriceService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PostgresSkuPricingConstraintTest extends TestCase
{
    use RefreshDatabase;

    private function requirePostgres(): void
    {
        if (
            DB::connection()->getDriverName()
            !== 'pgsql'
        ) {
            $this->markTestSkipped(
                'PostgreSQL-specific SKU pricing constraint test.'
            );
        }
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->create([
            'name_ar' => 'Tenant A',
            'name_en' => 'Tenant A',
            'country_code' => 'SA',
            'currency_code' => 'SAR',
            'vat_rate' => 15,
            'primary_color' => '#000000',
            'secondary_color' => '#FFFFFF',
            'is_active' => true,
        ]);
    }

    public function test_database_rejects_concurrent_safe_overlapping_price_window(): void
    {
        $this->requirePostgres();

        $tenant =
            $this->tenant();

        $context =
            app(TenantContext::class);

        $context->set(
            $tenant->id
        );

        try {
            $product =
                Product::query()->create([
                    'name_ar' => 'منتج',
                    'name_en' => 'Product',
                    'is_active' => true,
                ]);

            $sku =
                Sku::query()->create([
                    'product_id' => $product->id,

                    'code' => 'SKU-A',

                    'barcode' => 'BAR-SKU-A',

                    'track_inventory' => true,

                    'is_active' => true,
                ]);

            $from =
                CarbonImmutable::parse(
                    '2026-09-17 10:00:00'
                );

            $until =
                $from->addDay();

            app(
                SkuPriceService::class
            )->schedule(
                $sku,
                1000,
                1500,
                true,
                $from,
                $until,
            );

            $blocked = false;
            $sqlState = null;

            try {
                DB::transaction(
                    function () use (
                        $tenant,
                        $sku,
                        $from,
                        $until,
                    ): void {
                        DB::table(
                            'sku_prices'
                        )->insert([
                            'tenant_id' => $tenant->id,

                            'sku_id' => $sku->id,

                            'public_id' => (string) Str::uuid(),

                            'currency_code' => 'SAR',

                            'amount_minor' => 1200,

                            'tax_rate_bps' => 1500,

                            'tax_inclusive' => true,

                            'effective_from' => $from->addHour(),

                            'effective_until' => $until->addHour(),

                            'is_active' => true,

                            'created_at' => now(),

                            'updated_at' => now(),
                        ]);
                    }
                );
            } catch (
                QueryException $exception
            ) {
                $blocked = true;

                $sqlState =
                    (string) (
                        $exception
                            ->errorInfo[0]
                        ?? $exception
                            ->getCode()
                    );
            }

            $this->assertTrue(
                $blocked,
                'PostgreSQL allowed overlapping active SKU prices.'
            );

            $this->assertSame(
                '23P01',
                $sqlState,
                'Expected PostgreSQL exclusion-constraint violation.'
            );
        } finally {
            $context->clear();
        }
    }
}
