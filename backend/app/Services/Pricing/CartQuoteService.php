<?php

namespace App\Services\Pricing;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\Sku;
use App\Models\SkuPrice;
use App\Models\Tenant;
use App\Support\Cart\CartStatus;
use App\Support\Pricing\CheckoutQuote;
use App\Support\Pricing\QuoteLine;
use App\Support\Pricing\ShippingQuote;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

final class CartQuoteService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly SkuPriceService $prices,
        private readonly MoneyTaxCalculator $money,
    ) {}

    public function quote(
        Cart $cart,
        DateTimeInterface $quotedAt,
        DateTimeInterface $requestedExpiresAt,
        ShippingQuote $shipping,
    ): CheckoutQuote {
        $tenantId =
            $this->tenantContext->requireId();

        if (
            (int) $cart->tenant_id !==
            $tenantId
        ) {
            throw new LogicException(
                'Cart must belong to the active tenant.'
            );
        }

        $quoteTime =
            CarbonImmutable::instance(
                $quotedAt
            );

        $requestedExpiry =
            CarbonImmutable::instance(
                $requestedExpiresAt
            );

        if (
            ! $requestedExpiry->greaterThan(
                $quoteTime
            )
        ) {
            throw new InvalidArgumentException(
                'Requested quote expiration must be after quote creation.'
            );
        }

        return DB::transaction(
            function () use (
                $cart,
                $tenantId,
                $quoteTime,
                $requestedExpiry,
                $shipping,
            ): CheckoutQuote {
                /*
                 * Aggregate lock first.
                 *
                 * Quote creation must never trust a stale
                 * Cart model supplied by the caller.
                 */
                $lockedCart =
                    Cart::query()
                        ->whereKey(
                            $cart->id
                        )
                        ->lockForUpdate()
                        ->first();

                if (
                    $lockedCart === null ||
                    (int) $lockedCart->tenant_id
                        !== $tenantId
                ) {
                    throw new LogicException(
                        'Cart is not accessible in the active tenant.'
                    );
                }

                if (
                    $lockedCart->status !==
                    CartStatus::ACTIVE
                ) {
                    throw new LogicException(
                        'Only an active cart can be quoted.'
                    );
                }

                if (
                    $lockedCart->expires_at === null ||
                    ! $lockedCart
                        ->expires_at
                        ->greaterThan(
                            $quoteTime
                        )
                ) {
                    throw new LogicException(
                        'Cart is expired at the requested quote time.'
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

                if (
                    $shipping->currencyCode !==
                    $currency
                ) {
                    throw new LogicException(
                        'Shipping quote currency does not match cart currency.'
                    );
                }

                /*
                 * Requested expiry is policy supplied by
                 * the application layer.
                 *
                 * The domain may only shorten it when a
                 * dependency expires sooner.
                 */
                $effectiveExpiry =
                    $requestedExpiry;

                if (
                    $lockedCart
                        ->expires_at
                        ->lessThan(
                            $effectiveExpiry
                        )
                ) {
                    $effectiveExpiry =
                        CarbonImmutable::instance(
                            $lockedCart
                                ->expires_at
                        );
                }

                if (
                    $shipping->validUntil
                    !== null
                ) {
                    $shippingExpiry =
                        CarbonImmutable::instance(
                            $shipping
                                ->validUntil
                        );

                    if (
                        ! $shippingExpiry
                            ->greaterThan(
                                $quoteTime
                            )
                    ) {
                        throw new LogicException(
                            'Shipping quote is already expired.'
                        );
                    }

                    if (
                        $shippingExpiry
                            ->lessThan(
                                $effectiveExpiry
                            )
                    ) {
                        $effectiveExpiry =
                            $shippingExpiry;
                    }
                }

                /*
                 * Preserve the global Cart lock order:
                 *
                 * Cart
                 * -> Cart Items
                 *
                 * Future atomic conversion will begin with
                 * the same ordering.
                 */
                $items =
                    CartItem::query()
                        ->where(
                            'cart_id',
                            $lockedCart->id,
                        )
                        ->orderBy(
                            'location_id'
                        )
                        ->orderBy(
                            'sku_id'
                        )
                        ->orderBy(
                            'id'
                        )
                        ->lockForUpdate()
                        ->get();

                if ($items->isEmpty()) {
                    throw new LogicException(
                        'Cannot quote an empty cart.'
                    );
                }

                $skuIds =
                    $items
                        ->pluck('sku_id')
                        ->map(
                            fn ($id): int => (int) $id
                        )
                        ->unique()
                        ->sort()
                        ->values()
                        ->all();

                $locationIds =
                    $items
                        ->pluck(
                            'location_id'
                        )
                        ->map(
                            fn ($id): int => (int) $id
                        )
                        ->unique()
                        ->sort()
                        ->values()
                        ->all();

                /*
                 * Lock SKU rows before resolving prices.
                 *
                 * SkuPriceService::schedule() also takes
                 * the SKU lock first. This gives pricing
                 * mutations and quote construction the
                 * same serialization anchor.
                 */
                $skus =
                    Sku::query()
                        ->whereIn(
                            'id',
                            $skuIds,
                        )
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');

                $locations =
                    InventoryLocation::query()
                        ->whereIn(
                            'id',
                            $locationIds,
                        )
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');

                if (
                    $skus->count() !==
                        count($skuIds) ||
                    $locations->count() !==
                        count($locationIds)
                ) {
                    throw new LogicException(
                        'Cart inventory references are unavailable.'
                    );
                }

                $productIds =
                    $skus
                        ->pluck('product_id')
                        ->map(
                            fn ($id): int => (int) $id
                        )
                        ->unique()
                        ->sort()
                        ->values()
                        ->all();

                $products =
                    Product::query()
                        ->whereIn(
                            'id',
                            $productIds,
                        )
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');

                if (
                    $products->count() !==
                    count($productIds)
                ) {
                    throw new LogicException(
                        'Cart product references are unavailable.'
                    );
                }

                $lines = [];

                foreach ($items as $item) {
                    $sku =
                        $skus->get(
                            $item->sku_id
                        );

                    $location =
                        $locations->get(
                            $item->location_id
                        );

                    if (
                        $sku === null ||
                        $location === null
                    ) {
                        throw new LogicException(
                            'Cart inventory references are unavailable.'
                        );
                    }

                    $product =
                        $products->get(
                            $sku->product_id
                        );

                    if ($product === null) {
                        throw new LogicException(
                            'Cart product reference is unavailable.'
                        );
                    }

                    if (
                        ! $sku->is_active ||
                        ! $sku->track_inventory
                    ) {
                        throw new LogicException(
                            'Cart SKU is not currently sellable.'
                        );
                    }

                    if (! $product->is_active) {
                        throw new LogicException(
                            'Cart product is inactive.'
                        );
                    }

                    if (! $location->is_active) {
                        throw new LogicException(
                            'Cart inventory location is inactive.'
                        );
                    }

                    /*
                     * Resolve only from the trusted pricing
                     * source. No monetary value is supplied
                     * by the client or CartItem.
                     */
                    $resolvedPrice =
                        $this->prices->resolve(
                            $sku,
                            $quoteTime,
                        );

                    if ($resolvedPrice === null) {
                        throw new LogicException(
                            'No active trusted price exists for a cart SKU.'
                        );
                    }

                    /*
                     * Re-read and lock the exact price row.
                     *
                     * This closes the race between price
                     * resolution and monetary snapshot
                     * construction.
                     */
                    $price =
                        SkuPrice::query()
                            ->whereKey(
                                $resolvedPrice->id
                            )
                            ->lockForUpdate()
                            ->first();

                    if (
                        $price === null ||
                        ! $price->is_active ||
                        (int) $price->sku_id !==
                            (int) $sku->id ||
                        $price->currency_code !==
                            $currency ||
                        $price->effective_from
                            ->greaterThan(
                                $quoteTime
                            ) ||
                        (
                            $price->effective_until
                                !== null &&
                            ! $price
                                ->effective_until
                                ->greaterThan(
                                    $quoteTime
                                )
                        )
                    ) {
                        throw new LogicException(
                            'Trusted SKU price changed while the quote was being built.'
                        );
                    }

                    if (
                        $price->effective_until
                        !== null
                    ) {
                        $priceExpiry =
                            CarbonImmutable::instance(
                                $price
                                    ->effective_until
                            );

                        if (
                            $priceExpiry
                                ->lessThan(
                                    $effectiveExpiry
                                )
                        ) {
                            $effectiveExpiry =
                                $priceExpiry;
                        }
                    }

                    $quantity =
                        (int) $item->quantity;

                    $lineMoney =
                        $this->money
                            ->catalogLine(
                                unitAmountMinor: (int)
                                    $price
                                        ->amount_minor,

                                quantity: $quantity,

                                taxRateBps: (int)
                                    $price
                                        ->tax_rate_bps,

                                taxInclusive: (bool)
                                    $price
                                        ->tax_inclusive,
                            );

                    /*
                     * unitNetMinor is a unit snapshot.
                     *
                     * For tax-inclusive catalog pricing,
                     * lineSubtotalMinor is intentionally
                     * calculated at line level. Therefore
                     * rounding can make:
                     *
                     * unitNetMinor × quantity
                     *
                     * differ by one minor unit from the
                     * line-level net subtotal.
                     */
                    $unitNetMinor =
                        $price->tax_inclusive
                            ? $this->money
                                ->fromGross(
                                    (int)
                                    $price
                                        ->amount_minor,

                                    (int)
                                    $price
                                        ->tax_rate_bps,
                                )
                                ->netMinor

                            : (int)
                                $price
                                    ->amount_minor;

                    /*
                     * Discounts are deliberately zero here.
                     *
                     * A future promotion engine must be a
                     * trusted adjustment source. Cart/client
                     * input will never populate this field.
                     */
                    $lines[] =
                        new QuoteLine(
                            cartItemPublicId: $item->public_id,

                            skuId: (int)
                                $sku->id,

                            locationId: (int)
                                $location->id,

                            skuPricePublicId: $price->public_id,

                            currencyCode: $currency,

                            quantity: $quantity,

                            unitNetMinor: $unitNetMinor,

                            lineSubtotalMinor: $lineMoney
                                ->netMinor,

                            discountMinor: 0,

                            taxRateBps: (int)
                                $price
                                    ->tax_rate_bps,

                            taxMinor: $lineMoney
                                ->taxMinor,

                            lineTotalMinor: $lineMoney
                                ->grossMinor,
                        );
                }

                if (
                    ! $effectiveExpiry
                        ->greaterThan(
                            $quoteTime
                        )
                ) {
                    throw new LogicException(
                        'Quote validity collapsed because a dependency expires immediately.'
                    );
                }

                return new CheckoutQuote(
                    cartPublicId: $lockedCart->public_id,

                    currencyCode: $currency,

                    quotedAt: $quoteTime,

                    expiresAt: $effectiveExpiry,

                    lines: $lines,

                    shipping: $shipping->breakdown,
                );
            }
        );
    }
}
