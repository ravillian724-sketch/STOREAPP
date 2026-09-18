<?php

namespace App\Services\Storefront;

use App\Models\Cart;
use App\Models\Tenant;
use App\Services\Cart\CartCheckoutReservationService;
use App\Services\Pricing\CartQuoteService;
use App\Support\Pricing\CheckoutQuote;
use App\Support\Pricing\ShippingQuote;
use App\Support\Pricing\TaxBreakdown;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

final class StorefrontCheckoutService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CartCheckoutReservationService $reservations,
        private readonly CartQuoteService $quotes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function quote(
        Cart $cart,
    ): array {
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

        $quotedAt =
            CarbonImmutable::instance(
                now()
            );

        $requestedExpiry =
            $quotedAt->addMinutes(
                $this->quoteTtlMinutes()
            );

        return DB::transaction(
            function () use (
                $cart,
                $tenantId,
                $quotedAt,
                $requestedExpiry,
            ): array {
                $tenant =
                    Tenant::query()
                        ->whereKey($tenantId)
                        ->firstOrFail();

                $currency =
                    strtoupper(
                        trim(
                            (string) $tenant->currency_code
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

                /*
                 * Quote first from trusted server-side sources.
                 * No client amount, tax, discount, currency, or
                 * shipping charge participates in authority.
                 */
                $quote =
                    $this->quotes->quote(
                        $cart,
                        $quotedAt,
                        $requestedExpiry,
                        $this->zeroShipping(
                            $currency
                        ),
                    );

                /*
                 * Inventory hold is created in the SAME outer
                 * transaction. If reservation fails, quote work
                 * leaves no partial checkout state behind.
                 */
                $reservedCart =
                    $this->reservations->begin(
                        $cart,
                        $quote->expiresAt,
                    );

                $reservedUntil =
                    $reservedCart
                        ->inventory_reserved_until;

                if (
                    $reservedUntil === null ||
                    ! $reservedUntil->greaterThan(
                        $quotedAt
                    )
                ) {
                    throw new LogicException(
                        'Checkout inventory reservation is unavailable.'
                    );
                }

                /*
                 * Retry safety: begin() never extends an active
                 * stock hold. If a prior hold expires sooner,
                 * shorten the returned quote to that boundary.
                 */
                if (
                    $reservedUntil->lessThan(
                        $quote->expiresAt
                    )
                ) {
                    $quote =
                        $this->quotes->quote(
                            $reservedCart,
                            $quotedAt,
                            $reservedUntil,
                            $this->zeroShipping(
                                $currency
                            ),
                        );
                }

                return $this->present(
                    $quote,
                    $reservedUntil,
                );
            }
        );
    }

    private function zeroShipping(
        string $currency,
    ): ShippingQuote {
        return new ShippingQuote(
            currencyCode: $currency,
            breakdown: new TaxBreakdown(
                netMinor: 0,
                taxMinor: 0,
                grossMinor: 0,
                taxRateBps: 0,
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function present(
        CheckoutQuote $quote,
        CarbonImmutable $reservedUntil,
    ): array {
        return [
            'cart_id' => $quote->cartPublicId,
            'currency_code' => $quote->currencyCode,
            'inventory_reserved_until' =>
                $reservedUntil->format(DATE_ATOM),
            'quote' => [
                'quoted_at' =>
                    $quote->quotedAt->format(DATE_ATOM),
                'expires_at' =>
                    $quote->expiresAt->format(DATE_ATOM),
                'subtotal_minor' =>
                    $quote->subtotalMinor,
                'discount_minor' =>
                    $quote->discountMinor,
                'tax_minor' =>
                    $quote->taxMinor,
                'shipping_minor' =>
                    $quote->shippingMinor,
                'total_minor' =>
                    $quote->totalMinor,
                'lines' => array_map(
                    static fn ($line): array => [
                        'cart_item_id' =>
                            $line->cartItemPublicId,
                        'sku_id' =>
                            (string) $line->skuId,
                        'location_id' =>
                            (string) $line->locationId,
                        'sku_price_id' =>
                            $line->skuPricePublicId,
                        'quantity' =>
                            $line->quantity,
                        'unit_net_minor' =>
                            $line->unitNetMinor,
                        'line_subtotal_minor' =>
                            $line->lineSubtotalMinor,
                        'discount_minor' =>
                            $line->discountMinor,
                        'tax_rate_bps' =>
                            $line->taxRateBps,
                        'tax_minor' =>
                            $line->taxMinor,
                        'line_total_minor' =>
                            $line->lineTotalMinor,
                    ],
                    $quote->lines,
                ),
            ],
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
