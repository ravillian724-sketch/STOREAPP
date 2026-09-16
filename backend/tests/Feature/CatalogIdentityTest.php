<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sku;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(
        string $name,
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

    private function product(
        Tenant $tenant,
        string $name,
    ): Product {
        $context = app(
            TenantContext::class
        );

        $context->set($tenant->id);

        try {
            return Product::query()->create([
                'name_ar' => $name,
                'name_en' => $name,
                'is_active' => true,
            ]);
        } finally {
            $context->clear();
        }
    }

    private function sku(
        Tenant $tenant,
        Product $product,
        string $code,
        ?string $barcode = null,
    ): Sku {
        $context = app(
            TenantContext::class
        );

        $context->set($tenant->id);

        try {
            return Sku::query()->create([
                'product_id' => $product->id,

                'code' => $code,

                'barcode' => $barcode,

                'track_inventory' => true,

                'is_active' => true,
            ]);
        } finally {
            $context->clear();
        }
    }

    public function test_catalog_models_fail_closed_without_context(): void
    {
        $tenant = $this->tenant(
            'Tenant A'
        );

        $product = $this->product(
            $tenant,
            'Product A',
        );

        $this->sku(
            $tenant,
            $product,
            'SKU-A',
        );

        $this->assertSame(
            0,
            Product::query()->count(),
        );

        $this->assertSame(
            0,
            Sku::query()->count(),
        );
    }

    public function test_same_sku_code_can_exist_in_different_tenants(): void
    {
        $tenantA = $this->tenant(
            'Tenant A'
        );

        $tenantB = $this->tenant(
            'Tenant B'
        );

        $productA = $this->product(
            $tenantA,
            'Product A',
        );

        $productB = $this->product(
            $tenantB,
            'Product B',
        );

        $skuA = $this->sku(
            $tenantA,
            $productA,
            'SHARED-CODE',
        );

        $skuB = $this->sku(
            $tenantB,
            $productB,
            'SHARED-CODE',
        );

        $this->assertNotSame(
            $skuA->id,
            $skuB->id,
        );
    }

    public function test_duplicate_sku_code_is_blocked_inside_tenant(): void
    {
        $tenant = $this->tenant(
            'Tenant A'
        );

        $product = $this->product(
            $tenant,
            'Product A',
        );

        $this->sku(
            $tenant,
            $product,
            'SKU-001',
        );

        $context = app(
            TenantContext::class
        );

        $context->set($tenant->id);

        $blocked = false;

        try {
            try {
                DB::transaction(
                    function () use (
                        $product
                    ): void {
                        Sku::query()->create([
                            'product_id' => $product->id,

                            'code' => 'SKU-001',

                            'track_inventory' => true,

                            'is_active' => true,
                        ]);
                    }
                );
            } catch (QueryException) {
                $blocked = true;
            }

            $this->assertTrue(
                $blocked
            );
        } finally {
            $context->clear();
        }
    }

    public function test_sku_cannot_reference_product_from_another_tenant(): void
    {
        $tenantA = $this->tenant(
            'Tenant A'
        );

        $tenantB = $this->tenant(
            'Tenant B'
        );

        $productA = $this->product(
            $tenantA,
            'Product A',
        );

        $context = app(
            TenantContext::class
        );

        $context->set($tenantB->id);

        $blocked = false;

        try {
            try {
                DB::transaction(
                    function () use (
                        $productA
                    ): void {
                        Sku::query()->create([
                            'product_id' => $productA->id,

                            'code' => 'ILLEGAL',

                            'track_inventory' => true,

                            'is_active' => true,
                        ]);
                    }
                );
            } catch (QueryException) {
                $blocked = true;
            }

            $this->assertTrue(
                $blocked,
                'Cross-tenant product/SKU association was not blocked.',
            );
        } finally {
            $context->clear();
        }
    }

    public function test_caller_cannot_choose_product_tenant(): void
    {
        $tenantA = $this->tenant(
            'Tenant A'
        );

        $tenantB = $this->tenant(
            'Tenant B'
        );

        $context = app(
            TenantContext::class
        );

        $context->set($tenantA->id);

        try {
            $product = Product::query()
                ->create([
                    'tenant_id' => $tenantB->id,

                    'name_ar' => 'Safe Product',

                    'name_en' => 'Safe Product',

                    'is_active' => true,
                ]);

            $this->assertSame(
                $tenantA->id,
                $product->tenant_id,
            );
        } finally {
            $context->clear();
        }
    }

    public function test_postgres_raw_catalog_queries_are_tenant_isolated(): void
    {
        if (
            DB::connection()->getDriverName()
            !== 'pgsql'
        ) {
            $this->markTestSkipped(
                'PostgreSQL-specific catalog RLS test.'
            );
        }

        $tenant = $this->tenant(
            'Tenant A'
        );

        $product = $this->product(
            $tenant,
            'Product A',
        );

        $this->sku(
            $tenant,
            $product,
            'SKU-A',
        );

        $this->assertSame(
            0,
            DB::table('products')->count(),
        );

        $this->assertSame(
            0,
            DB::table('skus')->count(),
        );

        $context = app(
            TenantContext::class
        );

        $context->set($tenant->id);

        try {
            $this->assertSame(
                1,
                DB::table('products')
                    ->count(),
            );

            $this->assertSame(
                1,
                DB::table('skus')
                    ->count(),
            );
        } finally {
            $context->clear();
        }
    }
}
