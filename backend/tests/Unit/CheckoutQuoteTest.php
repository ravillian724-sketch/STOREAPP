<?php

namespace Tests\Unit;

use App\Support\Pricing\CheckoutQuote;
use App\Support\Pricing\QuoteLine;
use App\Support\Pricing\TaxBreakdown;
use DateTimeImmutable;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\TestCase;

class CheckoutQuoteTest extends TestCase
{
    private const CART_ID =
        '11111111-1111-4111-8111-111111111111';

    private const CART_ITEM_A =
        '22222222-2222-4222-8222-222222222222';

    private const CART_ITEM_B =
        '33333333-3333-4333-8333-333333333333';

    private const PRICE_A =
        '44444444-4444-4444-8444-444444444444';

    private const PRICE_B =
        '55555555-5555-4555-8555-555555555555';

    private function lineA(): QuoteLine
    {
        return new QuoteLine(
            cartItemPublicId: self::CART_ITEM_A,

            skuId: 10,
            locationId: 20,

            skuPricePublicId: self::PRICE_A,

            currencyCode: 'SAR',
            quantity: 2,

            unitNetMinor: 1000,
            lineSubtotalMinor: 2000,
            discountMinor: 0,

            taxRateBps: 1500,
            taxMinor: 300,

            lineTotalMinor: 2300,
        );
    }

    private function lineB(): QuoteLine
    {
        return new QuoteLine(
            cartItemPublicId: self::CART_ITEM_B,

            skuId: 11,
            locationId: 20,

            skuPricePublicId: self::PRICE_B,

            currencyCode: 'SAR',
            quantity: 1,

            unitNetMinor: 1000,
            lineSubtotalMinor: 1000,
            discountMinor: 100,

            taxRateBps: 1500,
            taxMinor: 135,

            lineTotalMinor: 1035,
        );
    }

    public function test_quote_derives_order_totals_from_lines_and_shipping(): void
    {
        $quote =
            new CheckoutQuote(
                cartPublicId: self::CART_ID,

                currencyCode: 'SAR',

                quotedAt: new DateTimeImmutable(
                    '2026-09-17 10:00:00'
                ),

                expiresAt: new DateTimeImmutable(
                    '2026-09-17 10:05:00'
                ),

                lines: [
                    $this->lineA(),
                    $this->lineB(),
                ],

                shipping: new TaxBreakdown(
                    netMinor: 500,
                    taxMinor: 75,
                    grossMinor: 575,
                    taxRateBps: 1500,
                ),
            );

        $this->assertSame(
            3000,
            $quote->subtotalMinor,
        );

        $this->assertSame(
            100,
            $quote->discountMinor,
        );

        $this->assertSame(
            510,
            $quote->taxMinor,
        );

        $this->assertSame(
            500,
            $quote->shippingMinor,
        );

        $this->assertSame(
            3910,
            $quote->totalMinor,
        );
    }

    public function test_quote_rejects_mixed_currency_lines(): void
    {
        $foreignCurrencyLine =
            new QuoteLine(
                cartItemPublicId: self::CART_ITEM_B,

                skuId: 11,
                locationId: 20,

                skuPricePublicId: self::PRICE_B,

                currencyCode: 'USD',

                quantity: 1,

                unitNetMinor: 1000,
                lineSubtotalMinor: 1000,
                discountMinor: 0,

                taxRateBps: 0,
                taxMinor: 0,

                lineTotalMinor: 1000,
            );

        $this->expectException(
            InvalidArgumentException::class
        );

        new CheckoutQuote(
            self::CART_ID,
            'SAR',
            new DateTimeImmutable(
                '2026-09-17 10:00:00'
            ),
            new DateTimeImmutable(
                '2026-09-17 10:05:00'
            ),
            [
                $this->lineA(),
                $foreignCurrencyLine,
            ],
            new TaxBreakdown(
                0,
                0,
                0,
                0,
            ),
        );
    }

    public function test_quote_rejects_duplicate_cart_item_lines(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        new CheckoutQuote(
            self::CART_ID,
            'SAR',
            new DateTimeImmutable(
                '2026-09-17 10:00:00'
            ),
            new DateTimeImmutable(
                '2026-09-17 10:05:00'
            ),
            [
                $this->lineA(),
                $this->lineA(),
            ],
            new TaxBreakdown(
                0,
                0,
                0,
                0,
            ),
        );
    }

    public function test_quote_line_rejects_inconsistent_financial_total(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        new QuoteLine(
            cartItemPublicId: self::CART_ITEM_A,

            skuId: 10,
            locationId: 20,

            skuPricePublicId: self::PRICE_A,

            currencyCode: 'SAR',
            quantity: 1,

            unitNetMinor: 1000,
            lineSubtotalMinor: 1000,
            discountMinor: 100,

            taxRateBps: 1500,
            taxMinor: 135,

            lineTotalMinor: 1200,
        );
    }

    public function test_quote_requires_non_empty_line_list(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        new CheckoutQuote(
            self::CART_ID,
            'SAR',
            new DateTimeImmutable(
                '2026-09-17 10:00:00'
            ),
            new DateTimeImmutable(
                '2026-09-17 10:05:00'
            ),
            [],
            new TaxBreakdown(
                0,
                0,
                0,
                0,
            ),
        );
    }

    public function test_quote_validity_window_is_half_open(): void
    {
        $quotedAt =
            new DateTimeImmutable(
                '2026-09-17 10:00:00'
            );

        $expiresAt =
            new DateTimeImmutable(
                '2026-09-17 10:05:00'
            );

        $quote =
            new CheckoutQuote(
                self::CART_ID,
                'SAR',
                $quotedAt,
                $expiresAt,
                [
                    $this->lineA(),
                ],
                new TaxBreakdown(
                    0,
                    0,
                    0,
                    0,
                ),
            );

        $this->assertFalse(
            $quote->isUsableAt(
                new DateTimeImmutable(
                    '2026-09-17 09:59:59'
                )
            )
        );

        $this->assertTrue(
            $quote->isUsableAt(
                $quotedAt
            )
        );

        $this->assertTrue(
            $quote->isUsableAt(
                new DateTimeImmutable(
                    '2026-09-17 10:04:59'
                )
            )
        );

        $this->assertFalse(
            $quote->isUsableAt(
                $expiresAt
            )
        );
    }

    public function test_quote_requires_expiration_after_creation(): void
    {
        $quotedAt =
            new DateTimeImmutable(
                '2026-09-17 10:00:00'
            );

        $this->expectException(
            InvalidArgumentException::class
        );

        new CheckoutQuote(
            self::CART_ID,
            'SAR',
            $quotedAt,
            $quotedAt,
            [
                $this->lineA(),
            ],
            new TaxBreakdown(
                0,
                0,
                0,
                0,
            ),
        );
    }

    public function test_quote_rejects_invalid_identity_and_currency(): void
    {
        $cases = [
            fn () => new QuoteLine(
                'not-a-uuid',
                1,
                1,
                self::PRICE_A,
                'SAR',
                1,
                100,
                100,
                0,
                0,
                0,
                100,
            ),

            fn () => new QuoteLine(
                self::CART_ITEM_A,
                0,
                1,
                self::PRICE_A,
                'SAR',
                1,
                100,
                100,
                0,
                0,
                0,
                100,
            ),

            fn () => new QuoteLine(
                self::CART_ITEM_A,
                1,
                1,
                self::PRICE_A,
                'sar',
                1,
                100,
                100,
                0,
                0,
                0,
                100,
            ),
        ];

        foreach ($cases as $case) {
            try {
                $case();

                $this->fail(
                    'Expected InvalidArgumentException.'
                );
            } catch (
                InvalidArgumentException
            ) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_quote_total_overflow_is_rejected(): void
    {
        $large =
            new QuoteLine(
                cartItemPublicId: self::CART_ITEM_A,

                skuId: 10,
                locationId: 20,

                skuPricePublicId: self::PRICE_A,

                currencyCode: 'SAR',
                quantity: 1,

                unitNetMinor: PHP_INT_MAX,

                lineSubtotalMinor: PHP_INT_MAX,

                discountMinor: 0,
                taxRateBps: 0,
                taxMinor: 0,

                lineTotalMinor: PHP_INT_MAX,
            );

        $small =
            new QuoteLine(
                cartItemPublicId: self::CART_ITEM_B,

                skuId: 11,
                locationId: 20,

                skuPricePublicId: self::PRICE_B,

                currencyCode: 'SAR',
                quantity: 1,

                unitNetMinor: 1,
                lineSubtotalMinor: 1,
                discountMinor: 0,
                taxRateBps: 0,
                taxMinor: 0,
                lineTotalMinor: 1,
            );

        $this->expectException(
            OverflowException::class
        );

        new CheckoutQuote(
            self::CART_ID,
            'SAR',
            new DateTimeImmutable(
                '2026-09-17 10:00:00'
            ),
            new DateTimeImmutable(
                '2026-09-17 10:05:00'
            ),
            [
                $large,
                $small,
            ],
            new TaxBreakdown(
                0,
                0,
                0,
                0,
            ),
        );
    }
}
