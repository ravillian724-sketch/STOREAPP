<?php

namespace Tests\Feature;

use App\Models\AppInstance;
use App\Models\Branch;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\Sku;
use App\Models\Tenant;
use App\Services\AppInstanceCredentialService;
use App\Services\Inventory\InventoryReservationService;
use App\Services\Inventory\StockLedgerService;
use App\Services\Pricing\SkuPriceService;
use App\Support\Inventory\InventoryMovementType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $tokens = [];

    private function tenant(
        string $alias,
        string $name,
    ): Tenant {
        $tenant = Tenant::query()->create([
            'name_ar' => $name,
            'name_en' => $name,
            'country_code' => 'SA',
            'currency_code' => 'SAR',
            'vat_rate' => 15,
            'primary_color' => '#006C67',
            'secondary_color' => '#0F172A',
            'is_active' => true,
        ]);

        $instance = AppInstance::query()->create([
            'tenant_id' => $tenant->id,
            'channel' => 'mobile',
            'is_active' => true,
        ]);

        $issued = app(
            AppInstanceCredentialService::class
        )->issue($instance);

        $this->tokens[$alias] = $issued->token;

        return $tenant;
    }

    private function inTenant(
        Tenant $tenant,
        callable $callback,
    ): mixed {
        $context = app(TenantContext::class);
        $previous = $context->id();
        $context->set($tenant->id);

        try {
            return $callback();
        } finally {
            if ($previous === null) {
                $context->clear();
            } else {
                $context->set($previous);
            }
        }
    }

    /** @return array{Branch, InventoryLocation} */
    private function branchAndLocation(
        Tenant $tenant,
        string $code,
    ): array {
        return $this->inTenant(
            $tenant,
            function () use ($code): array {
                $branch = Branch::query()->create([
                    'code' => $code,
                    'name_ar' => 'فرع '.$code,
                    'name_en' => 'Branch '.$code,
                    'is_active' => true,
                ]);

                $location = InventoryLocation::query()->create([
                    'branch_id' => $branch->id,
                    'code' => $code.'-STOCK',
                    'name_ar' => 'مخزون '.$code,
                    'name_en' => 'Stock '.$code,
                    'type' => 'stock',
                    'is_active' => true,
                ]);

                return [$branch, $location];
            },
        );
    }

    /** @return array{Product, Sku} */
    private function sellableProduct(
        Tenant $tenant,
        InventoryLocation $location,
        string $code,
        int $stock = 10,
        int $reserved = 0,
    ): array {
        return $this->inTenant(
            $tenant,
            function () use (
                $location,
                $code,
                $stock,
                $reserved,
            ): array {
                $product = Product::query()->create([
                    'name_ar' => 'منتج '.$code,
                    'name_en' => 'Product '.$code,
                    'description_ar' => 'وصف '.$code,
                    'description_en' => 'Description '.$code,
                    'image_url' => 'https://cdn.example.test/products/'.$code.'.png',
                    'is_active' => true,
                ]);

                $sku = Sku::query()->create([
                    'product_id' => $product->id,
                    'code' => $code,
                    'barcode' => '628'.str_pad($code, 8, '0', STR_PAD_LEFT),
                    'track_inventory' => true,
                    'is_active' => true,
                ]);

                app(SkuPriceService::class)->schedule(
                    $sku,
                    amountMinor: 2575,
                    taxRateBps: 1500,
                    taxInclusive: true,
                    effectiveFrom: now()->subMinute(),
                );

                app(StockLedgerService::class)->post(
                    $sku,
                    $location,
                    $stock,
                    InventoryMovementType::RECEIPT,
                    'receipt-'.$code,
                );

                if ($reserved > 0) {
                    app(InventoryReservationService::class)->reserve(
                        $sku,
                        $location,
                        $reserved,
                        'reserve-'.$code,
                        expiresAt: now()->addHour(),
                    );
                }

                return [$product, $sku];
            },
        );
    }

    public function test_home_returns_current_price_and_branch_ats(): void
    {
        $tenant = $this->tenant('a', 'Store A');
        [$branch, $location] = $this->branchAndLocation($tenant, 'MAIN');
        [$product, $sku] = $this->sellableProduct(
            $tenant,
            $location,
            '1001',
            stock: 10,
            reserved: 3,
        );

        $response = $this
            ->withHeaders([
                'X-App-Instance-Key' => $this->tokens['a'],
                'X-Branch-Id' => (string) $branch->id,
            ])
            ->getJson('/api/v1/storefront/home');

        $response
            ->assertOk()
            ->assertJsonPath('data.branch.id', (string) $branch->id)
            ->assertJsonPath('data.items.0.product_id', (string) $product->id)
            ->assertJsonPath('data.items.0.sku_id', (string) $sku->id)
            ->assertJsonPath('data.items.0.price.amount_minor', 2575)
            ->assertJsonPath('data.items.0.price.currency_code', 'SAR')
            ->assertJsonPath(
                'data.items.0.image_url',
                'https://cdn.example.test/products/1001.png',
            )
            ->assertJsonPath('data.items.0.availability.tracked', true)
            ->assertJsonPath('data.items.0.availability.available_to_sell', 7)
            ->assertJsonPath('data.items.0.availability.in_stock', true);
    }

    public function test_storefront_never_leaks_foreign_tenant_catalog(): void
    {
        $tenantA = $this->tenant('a', 'Store A');
        [$branchA, $locationA] = $this->branchAndLocation($tenantA, 'A');
        [$productA] = $this->sellableProduct($tenantA, $locationA, '2001');

        $tenantB = $this->tenant('b', 'Store B');
        [, $locationB] = $this->branchAndLocation($tenantB, 'B');
        [$productB] = $this->sellableProduct($tenantB, $locationB, '2002');

        $response = $this
            ->withHeaders([
                'X-App-Instance-Key' => $this->tokens['a'],
                'X-Branch-Id' => (string) $branchA->id,
            ])
            ->getJson('/api/v1/storefront/products?per_page=60');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.product_id', (string) $productA->id);

        $ids = collect($response->json('data.items'))
            ->pluck('product_id')
            ->all();

        $this->assertNotContains((string) $productB->id, $ids);
    }

    public function test_product_search_matches_barcode_and_hides_unpriced_products(): void
    {
        $tenant = $this->tenant('a', 'Store A');
        [$branch, $location] = $this->branchAndLocation($tenant, 'MAIN');
        [$product, $sku] = $this->sellableProduct($tenant, $location, '3001');

        $this->inTenant(
            $tenant,
            function (): void {
                $unpriced = Product::query()->create([
                    'name_ar' => 'بدون سعر',
                    'name_en' => 'Unpriced',
                    'is_active' => true,
                ]);

                Sku::query()->create([
                    'product_id' => $unpriced->id,
                    'code' => 'UNPRICED',
                    'track_inventory' => false,
                    'is_active' => true,
                ]);
            },
        );

        $response = $this
            ->withHeaders([
                'X-App-Instance-Key' => $this->tokens['a'],
                'X-Branch-Id' => (string) $branch->id,
            ])
            ->getJson(
                '/api/v1/storefront/products?q='.urlencode((string) $sku->barcode)
            );

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.product_id', (string) $product->id);
    }

    public function test_invalid_or_foreign_branch_is_rejected(): void
    {
        $tenantA = $this->tenant('a', 'Store A');
        $this->branchAndLocation($tenantA, 'A');

        $tenantB = $this->tenant('b', 'Store B');
        [$branchB] = $this->branchAndLocation($tenantB, 'B');

        $this->withHeaders([
            'X-App-Instance-Key' => $this->tokens['a'],
            'X-Branch-Id' => (string) $branchB->id,
        ])->getJson('/api/v1/storefront/home')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'BRANCH_NOT_FOUND');

        $this->withHeaders([
            'X-App-Instance-Key' => $this->tokens['a'],
            'X-Branch-Id' => 'not-a-number',
        ])->getJson('/api/v1/storefront/home')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'BRANCH_NOT_FOUND');
    }
}
