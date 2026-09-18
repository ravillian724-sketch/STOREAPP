<?php

namespace App\Services\Storefront;

use App\Models\Branch;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\SkuPrice;
use App\Models\Tenant;
use App\Services\Pricing\CartQuoteService;
use App\Services\Pricing\MoneyTaxCalculator;
use App\Support\Cart\CartStatus;
use App\Support\Pricing\ShippingQuote;
use App\Support\Pricing\TaxBreakdown;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use LogicException;
use RuntimeException;

final class StorefrontCartViewService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly StorefrontCartInventoryService $inventory,
        private readonly CartQuoteService $quotes,
        private readonly MoneyTaxCalculator $money,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(
        Cart $cart,
        Branch $branch,
    ): array {
        $tenantId =
            $this->tenantContext->requireId();

        if (
            (int) $cart->tenant_id !==
                $tenantId ||
            (int) $branch->tenant_id !==
                $tenantId
        ) {
            throw new LogicException(
                'Cart and branch must belong to the active tenant.'
            );
        }

        $this->inventory
            ->assertCartBranch(
                $cart,
                $branch,
            );

        $freshCart =
            Cart::query()
                ->whereKey(
                    $cart->id
                )
                ->first();

        if ($freshCart === null) {
            throw new LogicException(
                'Cart is no longer available.'
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

        $items =
            CartItem::query()
                ->with([
                    'sku.product',
                    'location',
                ])
                ->where(
                    'cart_id',
                    $freshCart->id,
                )
                ->orderBy('id')
                ->get();

        if ($items->isEmpty()) {
            return $this->payload(
                $freshCart,
                $branch,
                $currency,
                [],
                [
                    'subtotal_minor' => 0,
                    'discount_minor' => 0,
                    'tax_minor' => 0,
                    'shipping_minor' => 0,
                    'total_minor' => 0,
                    'quoted_at' => null,
                    'quote_expires_at' => null,
                ],
            );
        }

        if (
            $freshCart->status !==
            CartStatus::ACTIVE
        ) {
            throw new LogicException(
                'Only an active cart can be priced.'
            );
        }

        $quotedAt =
            CarbonImmutable::instance(
                now()
            );

        $ttlMinutes =
            $this->quoteTtlMinutes();

        $quote =
            $this->quotes->quote(
                $freshCart,
                $quotedAt,
                $quotedAt->addMinutes(
                    $ttlMinutes
                ),
                new ShippingQuote(
                    currencyCode: $currency,
                    breakdown: new TaxBreakdown(
                        netMinor: 0,
                        taxMinor: 0,
                        grossMinor: 0,
                        taxRateBps: 0,
                    ),
                ),
            );

        $quoteLines =
            collect($quote->lines)
                ->keyBy(
                    fn ($line): string => $line->cartItemPublicId
                );

        $pricePublicIds =
            collect($quote->lines)
                ->pluck(
                    'skuPricePublicId'
                )
                ->unique()
                ->values();

        $prices =
            SkuPrice::query()
                ->whereIn(
                    'public_id',
                    $pricePublicIds,
                )
                ->get()
                ->keyBy('public_id');

        if (
            $prices->count() !==
            $pricePublicIds->count()
        ) {
            throw new LogicException(
                'Trusted cart prices are unavailable.'
            );
        }

        $serializedItems = [];

        foreach ($items as $item) {
            $line =
                $quoteLines->get(
                    $item->public_id
                );

            if ($line === null) {
                throw new LogicException(
                    'Cart quote is missing an item.'
                );
            }

            $price =
                $prices->get(
                    $line->skuPricePublicId
                );

            if ($price === null) {
                throw new LogicException(
                    'Cart price snapshot is unavailable.'
                );
            }

            $sku = $item->sku;
            $product = $sku?->product;
            $location = $item->location;

            if (
                $sku === null ||
                $product === null ||
                $location === null
            ) {
                throw new LogicException(
                    'Cart catalog reference is unavailable.'
                );
            }

            $unitGross =
                $this->money
                    ->catalogLine(
                        unitAmountMinor: (int) $price->amount_minor,
                        quantity: 1,
                        taxRateBps: (int) $price->tax_rate_bps,
                        taxInclusive: (bool) $price->tax_inclusive,
                    )
                    ->grossMinor;

            $serializedItems[] = [
                'cart_item_id' => $item->public_id,

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

                'quantity' => (int) $item->quantity,

                'max_quantity' => $this->inventory
                    ->maxQuantity(
                        $item
                    ),

                'pricing' => [
                    'currency_code' => $line->currencyCode,

                    'catalog_unit_amount_minor' => (int) $price->amount_minor,

                    'display_unit_amount_minor' => $unitGross,

                    'tax_inclusive' => (bool) $price->tax_inclusive,

                    'unit_net_minor' => $line->unitNetMinor,

                    'line_subtotal_minor' => $line->lineSubtotalMinor,

                    'discount_minor' => $line->discountMinor,

                    'tax_rate_bps' => $line->taxRateBps,

                    'tax_minor' => $line->taxMinor,

                    'line_total_minor' => $line->lineTotalMinor,
                ],
            ];
        }

        return $this->payload(
            $freshCart,
            $branch,
            $currency,
            $serializedItems,
            [
                'subtotal_minor' => $quote->subtotalMinor,

                'discount_minor' => $quote->discountMinor,

                'tax_minor' => $quote->taxMinor,

                'shipping_minor' => $quote->shippingMinor,

                'total_minor' => $quote->totalMinor,

                'quoted_at' => $quote->quotedAt
                    ->format(DATE_ATOM),

                'quote_expires_at' => $quote->expiresAt
                    ->format(DATE_ATOM),
            ],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $totals
     * @return array<string, mixed>
     */
    private function payload(
        Cart $cart,
        Branch $branch,
        string $currency,
        array $items,
        array $totals,
    ): array {
        return [
            'cart' => [
                'id' => $cart->public_id,

                'status' => $cart->status,

                'expires_at' => $cart->expires_at
                    ?->format(DATE_ATOM),

                'inventory_reserved_until' => $cart
                    ->inventory_reserved_until
                    ?->format(DATE_ATOM),
            ],

            'branch' => [
                'id' => (string) $branch->id,

                'code' => $branch->code,

                'name_ar' => $branch->name_ar,

                'name_en' => $branch->name_en,
            ],

            'currency_code' => $currency,

            'items' => $items,

            'totals' => $totals,
        ];
    }

    private function quoteTtlMinutes(): int
    {
        $minutes = (int) config(
            'platform.storefront_cart_quote_ttl_minutes',
            5,
        );

        if (
            $minutes < 1 ||
            $minutes > 60
        ) {
            throw new RuntimeException(
                'Storefront cart quote TTL must be between 1 and 60 minutes.'
            );
        }

        return $minutes;
    }
}
