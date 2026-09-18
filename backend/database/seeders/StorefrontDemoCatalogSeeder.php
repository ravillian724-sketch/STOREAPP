<?php

namespace Database\Seeders;

use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\Sku;
use App\Models\Tenant;
use App\Services\Inventory\StockLedgerService;
use App\Services\Pricing\SkuPriceService;
use App\Support\Inventory\InventoryMovementType;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class StorefrontDemoCatalogSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'development', 'testing'])) {
            throw new RuntimeException(
                'Demo catalog seeding is forbidden outside non-production environments.'
            );
        }

        $tenant = $this->tenant();
        $context = app(TenantContext::class);
        $previousTenantId = $context->id();

        DB::transaction(function () use (
            $context,
            $previousTenantId,
            $tenant,
        ): void {
            $context->set((int) $tenant->id);

            try {
                $location = InventoryLocation::query()
                    ->where('type', 'stock')
                    ->where('is_active', true)
                    ->orderBy('id')
                    ->first();

                if ($location === null) {
                    throw new RuntimeException(
                        'Demo catalog requires an active stock location.'
                    );
                }

                foreach ($this->fixture() as $item) {
                    $sku = Sku::query()
                        ->where('code', $item['code'])
                        ->first();

                    if ($sku === null) {
                        $product = Product::query()->create([
                            'name_ar' => $item['name_ar'],
                            'name_en' => $item['name_en'],
                            'description_ar' => 'صنف تجريبي لاختبارات STOREAPP. السعر المعروض سعر وهمي للاختبار فقط.',
                            'description_en' => 'STOREAPP demo item. The displayed price is synthetic test data only.',
                            'image_url' => $item['image_url'],
                            'is_active' => true,
                        ]);

                        $sku = $product->skus()->create([
                            'code' => $item['code'],
                            'track_inventory' => true,
                            'is_active' => true,
                        ]);
                    } else {
                        $product = $sku->product()->firstOrFail();

                        $product->update([
                            'name_ar' => $item['name_ar'],
                            'name_en' => $item['name_en'],
                            'description_ar' => 'صنف تجريبي لاختبارات STOREAPP. السعر المعروض سعر وهمي للاختبار فقط.',
                            'description_en' => 'STOREAPP demo item. The displayed price is synthetic test data only.',
                            'image_url' => $item['image_url'],
                            'is_active' => true,
                        ]);

                        $sku->update([
                            'track_inventory' => true,
                            'is_active' => true,
                        ]);
                    }

                    $this->price($sku, (int) $item['test_price_minor']);

                    app(StockLedgerService::class)->post(
                        $sku,
                        $location,
                        50,
                        InventoryMovementType::RECEIPT,
                        'demo-catalog-stock-'.$item['code'],
                        'demo_catalog',
                        $item['code'],
                    );
                }
            } finally {
                if ($previousTenantId === null) {
                    $context->clear();
                } else {
                    $context->set($previousTenantId);
                }
            }
        });
    }

    private function tenant(): Tenant
    {
        $configuredId = trim(
            (string) env('STOREAPP_DEMO_TENANT_ID', '')
        );

        $query = Tenant::query()->where('is_active', true);

        $tenant = $configuredId !== ''
            ? $query->whereKey($configuredId)->first()
            : $query->where('name_en', 'STOREAPP Dev Pharmacy')->first();

        if ($tenant === null) {
            throw new RuntimeException(
                'Demo catalog tenant was not found.'
            );
        }

        return $tenant;
    }

    private function price(Sku $sku, int $amountMinor): void
    {
        $prices = app(SkuPriceService::class);
        $now = CarbonImmutable::instance(now())->setMicrosecond(0);
        $current = $prices->resolve($sku, $now);

        if (
            $current !== null &&
            (int) $current->amount_minor === $amountMinor &&
            (int) $current->tax_rate_bps === 1500 &&
            (bool) $current->tax_inclusive
        ) {
            return;
        }

        if ($current === null) {
            $prices->schedule(
                $sku,
                amountMinor: $amountMinor,
                taxRateBps: 1500,
                taxInclusive: true,
                effectiveFrom: $now->subMinute(),
            );

            return;
        }

        $prices->supersede(
            $sku,
            amountMinor: $amountMinor,
            taxRateBps: 1500,
            taxInclusive: true,
            effectiveFrom: $now,
        );
    }

    /**
     * @return list<array{
     *   code:string,
     *   name_en:string,
     *   name_ar:string,
     *   image_url:string,
     *   source_url:string,
     *   test_price_minor:int
     * }>
     */
    private function fixture(): array
    {
        $path = database_path(
            'seeders/fixtures/storefront_demo_products.json'
        );

        $decoded = json_decode(
            (string) file_get_contents($path),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        if (! is_array($decoded) || count($decoded) !== 20) {
            throw new RuntimeException(
                'Demo catalog fixture must contain exactly 20 products.'
            );
        }

        return $decoded;
    }
}
