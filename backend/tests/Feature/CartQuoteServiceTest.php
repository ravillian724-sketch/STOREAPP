<?php

namespace Tests\Feature;

use App\Models\AppInstance;
use App\Models\Cart;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\Sku;
use App\Models\Tenant;
use App\Services\Cart\CartLifecycleService;
use App\Services\Cart\CartService;
use App\Services\Pricing\CartQuoteService;
use App\Services\Pricing\SkuPriceService;
use App\Support\Pricing\CheckoutQuote;
use App\Support\Pricing\ShippingQuote;
use App\Support\Pricing\TaxBreakdown;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class CartQuoteServiceTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(
        string $name = 'Tenant A',
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

    private function appInstance(
        Tenant $tenant,
    ): AppInstance {
        return AppInstance::query()->create([
            'tenant_id' => $tenant->id,

            'channel' => 'mobile',

            'is_active' => true,
        ]);
    }

    private function createCart(
        Tenant $tenant,
        CarbonImmutable $expiresAt,
    ): Cart {
        $instance =
            $this->appInstance(
                $tenant
            );

        return $this->inTenant(
            $tenant,
            fn (): Cart => app(
                CartService::class
            )->create(
                $instance,
                $expiresAt,
            )->cart,
        );
    }

    private function inventory(
        Tenant $tenant,
        string $code = 'SKU-A',
    ): array {
        return $this->inTenant(
            $tenant,
            function () use (
                $code
            ): array {
                $product =
                    Product::query()
                        ->create([
                            'name_ar' => 'منتج '.$code,

                            'name_en' => 'Product '.$code,

                            'is_active' => true,
                        ]);

                $sku =
                    Sku::query()
                        ->create([
                            'product_id' => $product->id,

                            'code' => $code,

                            'barcode' => 'BAR-'.$code,

                            'name_ar' => 'عبوة',

                            'name_en' => 'Pack',

                            'track_inventory' => true,

                            'is_active' => true,
                        ]);

                $location =
                    InventoryLocation::query()
                        ->create([
                            'branch_id' => null,

                            'code' => 'LOC-'.$code,

                            'name_ar' => 'موقع '.$code,

                            'name_en' => 'Location '.$code,

                            'type' => 'stock',

                            'is_active' => true,
                        ]);

                return [
                    $product,
                    $sku,
                    $location,
                ];
            },
        );
    }

    private function addItem(
        Tenant $tenant,
        Cart $cart,
        Sku $sku,
        InventoryLocation $location,
        int $quantity,
    ): void {
        $this->inTenant(
            $tenant,
            fn () => app(
                CartService::class
            )->addItem(
                $cart,
                $sku,
                $location,
                $quantity,
            ),
        );
    }

    private function price(
        Tenant $tenant,
        Sku $sku,
        CarbonImmutable $from,
        ?CarbonImmutable $until = null,
        int $amountMinor = 1000,
        int $taxRateBps = 1500,
        bool $taxInclusive = true,
    ): void {
        $this->inTenant(
            $tenant,
            fn () => app(
                SkuPriceService::class
            )->schedule(
                $sku,
                $amountMinor,
                $taxRateBps,
                $taxInclusive,
                $from,
                $until,
            ),
        );
    }

    private function shipping(
        CarbonImmutable $validUntil,
        string $currency = 'SAR',
    ): ShippingQuote {
        return new ShippingQuote(
            currencyCode: $currency,

            breakdown: new TaxBreakdown(
                netMinor: 500,
                taxMinor: 75,
                grossMinor: 575,
                taxRateBps: 1500,
            ),

            validUntil: $validUntil,
        );
    }

    public function test_cart_quote_uses_trusted_price_and_line_level_tax_rounding(): void
    {
        $tenant =
            $this->tenant();

        $quotedAt =
            CarbonImmutable::now();

        $cart =
            $this->createCart(
                $tenant,
                $quotedAt->addDay(),
            );

        [
            $product,
            $sku,
            $location,
        ] = $this->inventory(
            $tenant
        );

        $this->addItem(
            $tenant,
            $cart,
            $sku,
            $location,
            3,
        );

        $this->price(
            $tenant,
            $sku,
            $quotedAt->subMinute(),
            null,
            1000,
            1500,
            true,
        );

        $quote =
            $this->inTenant(
                $tenant,
                fn (): CheckoutQuote => app(
                    CartQuoteService::class
                )->quote(
                    $cart,
                    $quotedAt,
                    $quotedAt
                        ->addMinutes(5),
                    $this->shipping(
                        $quotedAt
                            ->addMinutes(10)
                    ),
                ),
            );

        $this->assertSame(
            'SAR',
            $quote->currencyCode,
        );

        $this->assertCount(
            1,
            $quote->lines,
        );

        $line =
            $quote->lines[0];

        /*
         * 10.00 SAR tax-inclusive at 15%.
         *
         * Unit net rounds to 8.70.
         *
         * Three units are normalized at line level:
         * gross 30.00
         * tax    3.91
         * net   26.09
         */
        $this->assertSame(
            870,
            $line->unitNetMinor,
        );

        $this->assertSame(
            2609,
            $line->lineSubtotalMinor,
        );

        $this->assertSame(
            0,
            $line->discountMinor,
        );

        $this->assertSame(
            391,
            $line->taxMinor,
        );

        $this->assertSame(
            3000,
            $line->lineTotalMinor,
        );

        $this->assertSame(
            2609,
            $quote->subtotalMinor,
        );

        $this->assertSame(
            466,
            $quote->taxMinor,
        );

        $this->assertSame(
            500,
            $quote->shippingMinor,
        );

        $this->assertSame(
            3575,
            $quote->totalMinor,
        );

        $this->assertSame(
            $quotedAt
                ->addMinutes(5)
                ->getTimestamp(),
            $quote
                ->expiresAt
                ->getTimestamp(),
        );

        /*
         * Quoting is financially read-only.
         * It must not start inventory reservation.
         */
        $freshCart =
            $this->inTenant(
                $tenant,
                fn (): Cart => Cart::query()
                    ->findOrFail(
                        $cart->id
                    ),
            );

        $this->assertNull(
            $freshCart
                ->inventory_reserved_until
        );

        $this->assertTrue(
            $product->is_active
        );
    }

    public function test_cart_quote_rejects_missing_trusted_price(): void
    {
        $tenant =
            $this->tenant();

        $quotedAt =
            CarbonImmutable::now();

        $cart =
            $this->createCart(
                $tenant,
                $quotedAt->addDay(),
            );

        [
            ,
            $sku,
            $location,
        ] = $this->inventory(
            $tenant
        );

        $this->addItem(
            $tenant,
            $cart,
            $sku,
            $location,
            1,
        );

        $this->expectException(
            LogicException::class
        );

        $this->inTenant(
            $tenant,
            fn () => app(
                CartQuoteService::class
            )->quote(
                $cart,
                $quotedAt,
                $quotedAt
                    ->addMinutes(5),
                $this->shipping(
                    $quotedAt
                        ->addMinutes(10)
                ),
            ),
        );
    }

    public function test_cart_quote_rejects_empty_cart(): void
    {
        $tenant =
            $this->tenant();

        $quotedAt =
            CarbonImmutable::now();

        $cart =
            $this->createCart(
                $tenant,
                $quotedAt->addDay(),
            );

        $this->expectException(
            LogicException::class
        );

        $this->inTenant(
            $tenant,
            fn () => app(
                CartQuoteService::class
            )->quote(
                $cart,
                $quotedAt,
                $quotedAt
                    ->addMinutes(5),
                $this->shipping(
                    $quotedAt
                        ->addMinutes(10)
                ),
            ),
        );
    }

    public function test_cart_quote_rejects_foreign_tenant_cart(): void
    {
        $tenantA =
            $this->tenant(
                'Tenant A'
            );

        $tenantB =
            $this->tenant(
                'Tenant B'
            );

        $quotedAt =
            CarbonImmutable::now();

        $cart =
            $this->createCart(
                $tenantA,
                $quotedAt->addDay(),
            );

        $this->expectException(
            LogicException::class
        );

        $this->inTenant(
            $tenantB,
            fn () => app(
                CartQuoteService::class
            )->quote(
                $cart,
                $quotedAt,
                $quotedAt
                    ->addMinutes(5),
                $this->shipping(
                    $quotedAt
                        ->addMinutes(10)
                ),
            ),
        );
    }

    public function test_cart_quote_rejects_abandoned_cart(): void
    {
        $tenant =
            $this->tenant();

        $quotedAt =
            CarbonImmutable::now();

        $cart =
            $this->createCart(
                $tenant,
                $quotedAt->addDay(),
            );

        $cart =
            $this->inTenant(
                $tenant,
                fn (): Cart => app(
                    CartLifecycleService::class
                )->abandon(
                    $cart
                ),
            );

        $this->expectException(
            LogicException::class
        );

        $this->inTenant(
            $tenant,
            fn () => app(
                CartQuoteService::class
            )->quote(
                $cart,
                $quotedAt,
                $quotedAt
                    ->addMinutes(5),
                $this->shipping(
                    $quotedAt
                        ->addMinutes(10)
                ),
            ),
        );
    }

    public function test_cart_quote_rejects_shipping_currency_mismatch(): void
    {
        $tenant =
            $this->tenant();

        $quotedAt =
            CarbonImmutable::now();

        $cart =
            $this->createCart(
                $tenant,
                $quotedAt->addDay(),
            );

        $this->expectException(
            LogicException::class
        );

        $this->inTenant(
            $tenant,
            fn () => app(
                CartQuoteService::class
            )->quote(
                $cart,
                $quotedAt,
                $quotedAt
                    ->addMinutes(5),
                $this->shipping(
                    $quotedAt
                        ->addMinutes(10),
                    'USD',
                ),
            ),
        );
    }

    public function test_cart_quote_rejects_inactive_inventory_reference(): void
    {
        $tenant =
            $this->tenant();

        $quotedAt =
            CarbonImmutable::now();

        $cart =
            $this->createCart(
                $tenant,
                $quotedAt->addDay(),
            );

        [
            ,
            $sku,
            $location,
        ] = $this->inventory(
            $tenant
        );

        $this->addItem(
            $tenant,
            $cart,
            $sku,
            $location,
            1,
        );

        $this->price(
            $tenant,
            $sku,
            $quotedAt->subMinute(),
        );

        $this->inTenant(
            $tenant,
            function () use (
                $sku
            ): void {
                $sku->is_active =
                    false;

                $sku->save();
            },
        );

        $this->expectException(
            LogicException::class
        );

        $this->inTenant(
            $tenant,
            fn () => app(
                CartQuoteService::class
            )->quote(
                $cart,
                $quotedAt,
                $quotedAt
                    ->addMinutes(5),
                $this->shipping(
                    $quotedAt
                        ->addMinutes(10)
                ),
            ),
        );
    }

    public function test_quote_expiry_is_capped_by_price_validity(): void
    {
        $tenant =
            $this->tenant();

        $quotedAt =
            CarbonImmutable::now();

        $cart =
            $this->createCart(
                $tenant,
                $quotedAt
                    ->addMinutes(30),
            );

        [
            ,
            $sku,
            $location,
        ] = $this->inventory(
            $tenant
        );

        $this->addItem(
            $tenant,
            $cart,
            $sku,
            $location,
            1,
        );

        $priceExpiry =
            $quotedAt
                ->addMinutes(8);

        $this->price(
            $tenant,
            $sku,
            $quotedAt->subMinute(),
            $priceExpiry,
        );

        $quote =
            $this->inTenant(
                $tenant,
                fn (): CheckoutQuote => app(
                    CartQuoteService::class
                )->quote(
                    $cart,
                    $quotedAt,
                    $quotedAt
                        ->addMinutes(20),
                    $this->shipping(
                        $quotedAt
                            ->addMinutes(15)
                    ),
                ),
            );

        $this->assertSame(
            $priceExpiry
                ->getTimestamp(),
            $quote
                ->expiresAt
                ->getTimestamp(),
        );
    }

    public function test_quote_expiry_is_capped_by_shipping_validity(): void
    {
        $tenant =
            $this->tenant();

        $quotedAt =
            CarbonImmutable::now();

        $cart =
            $this->createCart(
                $tenant,
                $quotedAt
                    ->addMinutes(30),
            );

        [
            ,
            $sku,
            $location,
        ] = $this->inventory(
            $tenant
        );

        $this->addItem(
            $tenant,
            $cart,
            $sku,
            $location,
            1,
        );

        $this->price(
            $tenant,
            $sku,
            $quotedAt->subMinute(),
            $quotedAt
                ->addMinutes(15),
        );

        $shippingExpiry =
            $quotedAt
                ->addMinutes(7);

        $quote =
            $this->inTenant(
                $tenant,
                fn (): CheckoutQuote => app(
                    CartQuoteService::class
                )->quote(
                    $cart,
                    $quotedAt,
                    $quotedAt
                        ->addMinutes(20),
                    $this->shipping(
                        $shippingExpiry
                    ),
                ),
            );

        $this->assertSame(
            $shippingExpiry
                ->getTimestamp(),
            $quote
                ->expiresAt
                ->getTimestamp(),
        );
    }

    public function test_quote_expiry_is_capped_by_cart_validity(): void
    {
        $tenant =
            $this->tenant();

        $quotedAt =
            CarbonImmutable::now();

        $cartExpiry =
            $quotedAt
                ->addMinutes(6);

        $cart =
            $this->createCart(
                $tenant,
                $cartExpiry,
            );

        [
            ,
            $sku,
            $location,
        ] = $this->inventory(
            $tenant
        );

        $this->addItem(
            $tenant,
            $cart,
            $sku,
            $location,
            1,
        );

        $this->price(
            $tenant,
            $sku,
            $quotedAt->subMinute(),
            $quotedAt
                ->addMinutes(15),
        );

        $quote =
            $this->inTenant(
                $tenant,
                fn (): CheckoutQuote => app(
                    CartQuoteService::class
                )->quote(
                    $cart,
                    $quotedAt,
                    $quotedAt
                        ->addMinutes(20),
                    $this->shipping(
                        $quotedAt
                            ->addMinutes(12)
                    ),
                ),
            );

        $this->assertSame(
            $cartExpiry
                ->getTimestamp(),
            $quote
                ->expiresAt
                ->getTimestamp(),
        );
    }
}
