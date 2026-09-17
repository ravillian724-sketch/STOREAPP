<?php

namespace Tests\Unit;

use App\Services\Pricing\MoneyTaxCalculator;
use App\Support\Pricing\TaxBreakdown;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\TestCase;

class MoneyTaxCalculatorTest extends TestCase
{
    private MoneyTaxCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator =
            new MoneyTaxCalculator;
    }

    public function test_tax_exclusive_catalog_line_is_calculated_in_minor_units(): void
    {
        $result =
            $this->calculator
                ->catalogLine(
                    unitAmountMinor: 1000,
                    quantity: 2,
                    taxRateBps: 1500,
                    taxInclusive: false,
                );

        $this->assertSame(
            2000,
            $result->netMinor,
        );

        $this->assertSame(
            300,
            $result->taxMinor,
        );

        $this->assertSame(
            2300,
            $result->grossMinor,
        );
    }

    public function test_tax_inclusive_catalog_line_preserves_exact_gross(): void
    {
        $result =
            $this->calculator
                ->catalogLine(
                    unitAmountMinor: 1150,
                    quantity: 2,
                    taxRateBps: 1500,
                    taxInclusive: true,
                );

        $this->assertSame(
            2000,
            $result->netMinor,
        );

        $this->assertSame(
            300,
            $result->taxMinor,
        );

        $this->assertSame(
            2300,
            $result->grossMinor,
        );
    }

    public function test_inclusive_rounding_occurs_at_line_level_without_losing_gross(): void
    {
        $result =
            $this->calculator
                ->catalogLine(
                    unitAmountMinor: 1000,
                    quantity: 3,
                    taxRateBps: 1500,
                    taxInclusive: true,
                );

        $this->assertSame(
            3000,
            $result->grossMinor,
        );

        $this->assertSame(
            391,
            $result->taxMinor,
        );

        $this->assertSame(
            2609,
            $result->netMinor,
        );

        $this->assertSame(
            $result->grossMinor,
            $result->netMinor
                + $result->taxMinor,
        );
    }

    public function test_zero_tax_is_exact_for_inclusive_and_exclusive_amounts(): void
    {
        $exclusive =
            $this->calculator
                ->fromNet(
                    2575,
                    0,
                );

        $inclusive =
            $this->calculator
                ->fromGross(
                    2575,
                    0,
                );

        foreach (
            [
                $exclusive,
                $inclusive,
            ] as $result
        ) {
            $this->assertSame(
                2575,
                $result->netMinor,
            );

            $this->assertSame(
                0,
                $result->taxMinor,
            );

            $this->assertSame(
                2575,
                $result->grossMinor,
            );
        }
    }

    public function test_one_hundred_percent_tax_is_supported(): void
    {
        $exclusive =
            $this->calculator
                ->fromNet(
                    1000,
                    10000,
                );

        $this->assertSame(
            1000,
            $exclusive->taxMinor,
        );

        $this->assertSame(
            2000,
            $exclusive->grossMinor,
        );

        $inclusive =
            $this->calculator
                ->fromGross(
                    2000,
                    10000,
                );

        $this->assertSame(
            1000,
            $inclusive->netMinor,
        );

        $this->assertSame(
            1000,
            $inclusive->taxMinor,
        );
    }

    public function test_half_up_rounding_is_deterministic(): void
    {
        /*
         * 1 × 50% = 0.5 minor unit.
         * Half-up must produce 1.
         */
        $result =
            $this->calculator
                ->fromNet(
                    1,
                    5000,
                );

        $this->assertSame(
            1,
            $result->taxMinor,
        );

        $this->assertSame(
            2,
            $result->grossMinor,
        );
    }

    public function test_invalid_inputs_are_rejected(): void
    {
        $cases = [
            fn () => $this->calculator
                ->catalogLine(
                    -1,
                    1,
                    1500,
                    false,
                ),

            fn () => $this->calculator
                ->catalogLine(
                    1000,
                    0,
                    1500,
                    false,
                ),

            fn () => $this->calculator
                ->fromNet(
                    1000,
                    -1,
                ),

            fn () => $this->calculator
                ->fromGross(
                    1000,
                    10001,
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

    public function test_multiplication_overflow_is_rejected(): void
    {
        $this->expectException(
            OverflowException::class
        );

        $this->calculator
            ->catalogLine(
                PHP_INT_MAX,
                2,
                0,
                false,
            );
    }

    public function test_tax_numerator_overflow_is_rejected(): void
    {
        $this->expectException(
            OverflowException::class
        );

        $this->calculator
            ->fromNet(
                PHP_INT_MAX,
                10000,
            );
    }

    public function test_value_object_rejects_inconsistent_totals(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        new TaxBreakdown(
            netMinor: 1000,
            taxMinor: 150,
            grossMinor: 1200,
            taxRateBps: 1500,
        );
    }
}
