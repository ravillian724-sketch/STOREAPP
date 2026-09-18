<?php

namespace App\Services\Storefront;

use App\Models\Cart;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Tenant;
use App\Services\Cart\CartCheckoutReservationService;
use App\Services\Order\OrderConversionService;
use App\Services\Payment\PaymentService;
use App\Services\Pricing\CartQuoteService;
use App\Support\Order\OrderCheckoutSnapshot;
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
        private readonly OrderConversionService $orders,
        private readonly PaymentService $payments,
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

    /**
     * @param  array<string, mixed>  $checkoutData
     * @return array<string, mixed>
     */
    public function createOrder(
        Cart $cart,
        array $checkoutData,
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

        $instant =
            CarbonImmutable::instance(
                now()
            );

        $freshCart =
            Cart::query()
                ->whereKey(
                    $cart->id
                )
                ->first();

        if ($freshCart === null) {
            throw new LogicException(
                'Cart is unavailable.'
            );
        }

        /*
         * Active carts must have an unexpired checkout hold.
         * Converted carts use an arbitrary future quote
         * deadline only so OrderConversionService can replay
         * the authoritative persisted order idempotently.
         */
        $requestedQuoteExpiry =
            $freshCart->inventory_reserved_until !== null &&
            $freshCart
                ->inventory_reserved_until
                ->greaterThan(
                    $instant
                )
                ? CarbonImmutable::instance(
                    $freshCart
                        ->inventory_reserved_until
                )
                : $instant->addMinute();

        $tenant =
            Tenant::query()
                ->whereKey(
                    $tenantId
                )
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

        $checkout =
            new OrderCheckoutSnapshot(
                customerName:
                    $checkoutData['customer_name']
                    ?? null,
                customerPhone:
                    $checkoutData['customer_phone']
                    ?? null,
                customerEmail:
                    $checkoutData['customer_email']
                    ?? null,
                shippingAddressSnapshot:
                    $checkoutData['shipping_address']
                    ?? null,
            );

        $order =
            $this->orders->convert(
                $freshCart,
                $instant,
                $requestedQuoteExpiry,
                $this->zeroShipping(
                    $currency
                ),
                $checkout,
            );

        return $this->presentOrder(
            $order
        );
    }

    /**
     * Create or replay the authoritative provider attempt for
     * the order already converted from this cart.
     *
     * @return array<string, mixed>
     */
    public function createPaymentAttempt(
        Cart $cart,
        string $idempotencyKey,
        string $providerCode,
        string $methodCode,
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

        $order =
            Order::query()
                ->where(
                    'cart_id',
                    $cart->id,
                )
                ->first();

        if ($order === null) {
            throw new LogicException(
                'Checkout order is unavailable for this cart.'
            );
        }

        if (
            (int) $order->app_instance_id !==
            (int) $cart->app_instance_id
        ) {
            throw new LogicException(
                'Checkout order does not match cart app instance.'
            );
        }

        $payment =
            $this->payments->forOrder(
                $order
            );

        $attempt =
            $this->payments->createAttempt(
                $payment,
                $idempotencyKey,
                $providerCode,
                $methodCode,
            );

        return $this->presentPaymentAttempt(
            $payment,
            $attempt,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPaymentAttempt(
        Payment $payment,
        PaymentAttempt $attempt,
    ): array {
        return [
            'payment' => [
                'id' => $payment->public_id,
                'status' => $payment->status,
                'currency_code' =>
                    $payment->currency_code,
                'amount_minor' =>
                    (int) $payment->amount_minor,
            ],
            'attempt' => [
                'id' => $attempt->public_id,
                'status' => $attempt->status,
                'provider_code' =>
                    $attempt->provider_code,
                'method_code' =>
                    $attempt->method_code,
                'currency_code' =>
                    $attempt->currency_code,
                'amount_minor' =>
                    (int) $attempt->amount_minor,
                'provider_reference' =>
                    $attempt->provider_reference,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentOrder(
        Order $order,
    ): array {
        $order->loadMissing(
            'items'
        );

        return [
            'order' => [
                'id' => $order->public_id,
                'status' => $order->status,
                'currency_code' =>
                    $order->currency_code,
                'subtotal_minor' =>
                    (int) $order->subtotal_minor,
                'discount_minor' =>
                    (int) $order->discount_minor,
                'tax_minor' =>
                    (int) $order->tax_minor,
                'shipping_minor' =>
                    (int) $order->shipping_minor,
                'total_minor' =>
                    (int) $order->total_minor,
                'customer_name' =>
                    $order->customer_name,
                'customer_phone' =>
                    $order->customer_phone,
                'customer_email' =>
                    $order->customer_email,
                'shipping_address' =>
                    $order
                        ->shipping_address_snapshot,
                'items' => $order->items
                    ->map(
                        static fn ($item): array => [
                            'id' =>
                                $item->public_id,
                            'sku_id' =>
                                (string) $item->sku_id,
                            'location_id' =>
                                (string) $item
                                    ->location_id,
                            'sku_code' =>
                                $item
                                    ->sku_code_snapshot,
                            'product_name_ar' =>
                                $item
                                    ->product_name_ar_snapshot,
                            'product_name_en' =>
                                $item
                                    ->product_name_en_snapshot,
                            'quantity' =>
                                (int) $item->quantity,
                            'unit_net_minor' =>
                                (int) $item
                                    ->unit_net_minor,
                            'tax_minor' =>
                                (int) $item->tax_minor,
                            'line_total_minor' =>
                                (int) $item
                                    ->line_total_minor,
                        ]
                    )
                    ->values()
                    ->all(),
            ],
        ];
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
