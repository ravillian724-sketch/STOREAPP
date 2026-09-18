<?php

namespace App\Services\Storefront;

use App\Models\Branch;
use App\Models\InventoryLocation;
use App\Models\InventoryReservation;
use App\Models\Product;
use App\Models\Sku;
use App\Models\SkuPrice;
use App\Models\StockLedgerEntry;
use App\Models\Tenant;
use App\Support\Inventory\InventoryReservationStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use LogicException;

final class StorefrontCatalogService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @return array{
     *   branch: array<string, mixed>|null,
     *   items: array<int, array<string, mixed>>,
     *   pagination: array{current_page:int,per_page:int,has_more:bool}
     * }
     */
    public function page(
        Tenant $tenant,
        ?Branch $branch,
        ?string $search,
        int $perPage,
    ): array {
        $tenantId = $this->tenantContext->requireId();

        if ((int) $tenant->id !== $tenantId) {
            throw new LogicException(
                'Storefront tenant does not match active tenant context.'
            );
        }

        if (
            $branch !== null &&
            (int) $branch->tenant_id !== $tenantId
        ) {
            throw new LogicException(
                'Storefront branch does not belong to active tenant.'
            );
        }

        $perPage = max(1, min(60, $perPage));
        $currency = strtoupper(trim((string) $tenant->currency_code));
        $now = now();
        $search = trim((string) $search);

        $query = Product::query()
            ->where('is_active', true)
            ->whereHas(
                'skus',
                fn (Builder|Relation $skuQuery): Builder|Relation => $this->pricedSkuScope(
                    $skuQuery,
                    $currency,
                    $now,
                ),
            )
            ->with([
                'skus' => fn (Builder|Relation $skuQuery): Builder|Relation => $this->pricedSkuScope(
                    $skuQuery,
                    $currency,
                    $now,
                )
                    ->with([
                        'prices' => fn (Builder|Relation $priceQuery): Builder|Relation => $this->currentPriceScope(
                            $priceQuery,
                            $currency,
                            $now,
                        ),
                    ])
                    ->orderBy('id'),
            ]);

        if ($search !== '') {
            $query->where(
                function (Builder $productQuery) use ($search): void {
                    $like = '%'.$search.'%';

                    $productQuery
                        ->where('name_ar', 'like', $like)
                        ->orWhere('name_en', 'like', $like)
                        ->orWhereHas(
                            'skus',
                            function (Builder $skuQuery) use ($like): void {
                                $skuQuery
                                    ->where('is_active', true)
                                    ->where(
                                        function (Builder $fields) use ($like): void {
                                            $fields
                                                ->where('code', 'like', $like)
                                                ->orWhere('barcode', 'like', $like)
                                                ->orWhere('name_ar', 'like', $like)
                                                ->orWhere('name_en', 'like', $like);
                                        }
                                    );
                            },
                        );
                },
            );
        }

        $page = $query
            ->orderBy('id')
            ->simplePaginate($perPage);

        /** @var Collection<int, Product> $products */
        $products = collect($page->items());

        /** @var Collection<int, Sku> $selectedSkus */
        $selectedSkus = $products
            ->map(
                fn (Product $product): ?Sku => $product->skus->first()
            )
            ->filter()
            ->values();

        $availability = $this->availabilityFor(
            $branch,
            $selectedSkus,
        );

        return [
            'branch' => $branch === null
                ? null
                : [
                    'id' => (string) $branch->id,
                    'code' => $branch->code,
                    'name_ar' => $branch->name_ar,
                    'name_en' => $branch->name_en,
                ],
            'items' => $products
                ->map(
                    fn (Product $product): array => $this->serializeProduct(
                        $product,
                        $availability,
                    )
                )
                ->values()
                ->all(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'has_more' => $page->hasMorePages(),
            ],
        ];
    }

    private function pricedSkuScope(
        Builder|Relation $query,
        string $currency,
        mixed $now,
    ): Builder|Relation {
        return $query
            ->where('is_active', true)
            ->whereHas(
                'prices',
                fn (Builder|Relation $priceQuery): Builder|Relation => $this->currentPriceScope(
                    $priceQuery,
                    $currency,
                    $now,
                ),
            );
    }

    private function currentPriceScope(
        Builder|Relation $query,
        string $currency,
        mixed $now,
    ): Builder|Relation {
        return $query
            ->where('currency_code', $currency)
            ->where('is_active', true)
            ->where('effective_from', '<=', $now)
            ->where(
                function (Builder $window) use ($now): void {
                    $window
                        ->whereNull('effective_until')
                        ->orWhere('effective_until', '>', $now);
                },
            )
            ->orderByDesc('effective_from');
    }

    /**
     * @param  Collection<int, Sku>  $skus
     * @return array<int, array{tracked:bool,available_to_sell:int|null,in_stock:bool|null}>
     */
    private function availabilityFor(
        ?Branch $branch,
        Collection $skus,
    ): array {
        if ($skus->isEmpty()) {
            return [];
        }

        $result = [];

        foreach ($skus as $sku) {
            if (! $sku->track_inventory) {
                $result[(int) $sku->id] = [
                    'tracked' => false,
                    'available_to_sell' => null,
                    'in_stock' => true,
                ];
            } elseif ($branch === null) {
                $result[(int) $sku->id] = [
                    'tracked' => true,
                    'available_to_sell' => null,
                    'in_stock' => null,
                ];
            }
        }

        if ($branch === null) {
            return $result;
        }

        $trackedSkuIds = $skus
            ->filter(fn (Sku $sku): bool => $sku->track_inventory)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values();

        if ($trackedSkuIds->isEmpty()) {
            return $result;
        }

        $locationIds = InventoryLocation::query()
            ->where('branch_id', $branch->id)
            ->where('type', 'stock')
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values();

        if ($locationIds->isEmpty()) {
            foreach ($trackedSkuIds as $skuId) {
                $result[$skuId] = [
                    'tracked' => true,
                    'available_to_sell' => 0,
                    'in_stock' => false,
                ];
            }

            return $result;
        }

        $onHand = StockLedgerEntry::query()
            ->selectRaw('sku_id, SUM(quantity_delta) AS quantity')
            ->whereIn('sku_id', $trackedSkuIds->all())
            ->whereIn('location_id', $locationIds->all())
            ->groupBy('sku_id')
            ->pluck('quantity', 'sku_id');

        $now = now();

        $reserved = InventoryReservation::query()
            ->selectRaw('sku_id, SUM(quantity) AS quantity')
            ->whereIn('sku_id', $trackedSkuIds->all())
            ->whereIn('location_id', $locationIds->all())
            ->where('status', InventoryReservationStatus::ACTIVE)
            ->where(
                function (Builder $window) use ($now): void {
                    $window
                        ->whereNull('expires_at')
                        ->orWhere('expires_at', '>', $now);
                },
            )
            ->groupBy('sku_id')
            ->pluck('quantity', 'sku_id');

        foreach ($trackedSkuIds as $skuId) {
            $available = max(
                0,
                (int) ($onHand[$skuId] ?? 0) -
                (int) ($reserved[$skuId] ?? 0),
            );

            $result[$skuId] = [
                'tracked' => true,
                'available_to_sell' => $available,
                'in_stock' => $available > 0,
            ];
        }

        return $result;
    }

    /**
     * @param  array<int, array{tracked:bool,available_to_sell:int|null,in_stock:bool|null}>  $availability
     * @return array<string, mixed>
     */
    private function serializeProduct(
        Product $product,
        array $availability,
    ): array {
        /** @var Sku $sku */
        $sku = $product->skus->first();

        /** @var SkuPrice $price */
        $price = $sku->prices->first();

        return [
            'product_id' => (string) $product->id,
            'sku_id' => (string) $sku->id,
            'code' => $sku->code,
            'barcode' => $sku->barcode,
            'name_ar' => $product->name_ar,
            'name_en' => $product->name_en,
            'variant_name_ar' => $sku->name_ar,
            'variant_name_en' => $sku->name_en,
            'description_ar' => $product->description_ar,
            'description_en' => $product->description_en,
            'image_url' => $product->image_url,
            'price' => [
                'amount_minor' => (int) $price->amount_minor,
                'currency_code' => $price->currency_code,
                'tax_rate_bps' => (int) $price->tax_rate_bps,
                'tax_inclusive' => (bool) $price->tax_inclusive,
            ],
            'availability' => $availability[(int) $sku->id] ?? [
                'tracked' => (bool) $sku->track_inventory,
                'available_to_sell' => null,
                'in_stock' => null,
            ],
        ];
    }
}
