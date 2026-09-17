<?php

namespace App\Services\Pricing;

use App\Support\Pricing\TaxBreakdown;
use InvalidArgumentException;
use OverflowException;

final class MoneyTaxCalculator
{
    private const BASIS_POINTS = 10000;

    public function catalogLine(
        int $unitAmountMinor,
        int $quantity,
        int $taxRateBps,
        bool $taxInclusive,
    ): TaxBreakdown {
        if ($unitAmountMinor < 0) {
            throw new InvalidArgumentException(
                'Unit amount cannot be negative.'
            );
        }

        if ($quantity <= 0) {
            throw new InvalidArgumentException(
                'Quantity must be positive.'
            );
        }

        $this->validateTaxRate(
            $taxRateBps
        );

        $lineAmount =
            $this->multiplySafely(
                $unitAmountMinor,
                $quantity,
            );

        return $taxInclusive
            ? $this->fromGross(
                $lineAmount,
                $taxRateBps,
            )
            : $this->fromNet(
                $lineAmount,
                $taxRateBps,
            );
    }

    public function fromNet(
        int $netMinor,
        int $taxRateBps,
    ): TaxBreakdown {
        if ($netMinor < 0) {
            throw new InvalidArgumentException(
                'Net amount cannot be negative.'
            );
        }

        $this->validateTaxRate(
            $taxRateBps
        );

        $taxMinor =
            $this->roundedRatio(
                $netMinor,
                $taxRateBps,
                self::BASIS_POINTS,
            );

        $grossMinor =
            $this->addSafely(
                $netMinor,
                $taxMinor,
            );

        return new TaxBreakdown(
            netMinor: $netMinor,
            taxMinor: $taxMinor,
            grossMinor: $grossMinor,
            taxRateBps: $taxRateBps,
        );
    }

    public function fromGross(
        int $grossMinor,
        int $taxRateBps,
    ): TaxBreakdown {
        if ($grossMinor < 0) {
            throw new InvalidArgumentException(
                'Gross amount cannot be negative.'
            );
        }

        $this->validateTaxRate(
            $taxRateBps
        );

        if ($taxRateBps === 0) {
            return new TaxBreakdown(
                netMinor: $grossMinor,
                taxMinor: 0,
                grossMinor: $grossMinor,
                taxRateBps: 0,
            );
        }

        /*
         * Inclusive VAT extraction:
         *
         * tax =
         * gross × rate /
         * (10000 + rate)
         *
         * Integer half-up rounding is applied once
         * at the monetary amount being normalized.
         */
        $taxMinor =
            $this->roundedRatio(
                $grossMinor,
                $taxRateBps,
                self::BASIS_POINTS
                    + $taxRateBps,
            );

        return new TaxBreakdown(
            netMinor: $grossMinor - $taxMinor,

            taxMinor: $taxMinor,

            grossMinor: $grossMinor,

            taxRateBps: $taxRateBps,
        );
    }

    private function validateTaxRate(
        int $taxRateBps,
    ): void {
        if (
            $taxRateBps < 0 ||
            $taxRateBps > self::BASIS_POINTS
        ) {
            throw new InvalidArgumentException(
                'Tax rate must be between 0 and 10000 basis points.'
            );
        }
    }

    private function roundedRatio(
        int $amount,
        int $multiplier,
        int $denominator,
    ): int {
        if (
            $amount === 0 ||
            $multiplier === 0
        ) {
            return 0;
        }

        $numerator =
            $this->multiplySafely(
                $amount,
                $multiplier,
            );

        /*
         * All monetary inputs are non-negative.
         * Adding half the denominator implements
         * deterministic integer half-up rounding.
         */
        $half =
            intdiv(
                $denominator,
                2,
            );

        $adjusted =
            $this->addSafely(
                $numerator,
                $half,
            );

        return intdiv(
            $adjusted,
            $denominator,
        );
    }

    private function multiplySafely(
        int $left,
        int $right,
    ): int {
        if (
            $left < 0 ||
            $right < 0
        ) {
            throw new InvalidArgumentException(
                'Money arithmetic accepts only non-negative integers.'
            );
        }

        if (
            $left !== 0 &&
            $right >
                intdiv(
                    PHP_INT_MAX,
                    $left,
                )
        ) {
            throw new OverflowException(
                'Money multiplication overflow.'
            );
        }

        return $left * $right;
    }

    private function addSafely(
        int $left,
        int $right,
    ): int {
        if (
            $left < 0 ||
            $right < 0
        ) {
            throw new InvalidArgumentException(
                'Money arithmetic accepts only non-negative integers.'
            );
        }

        if (
            $right >
            PHP_INT_MAX - $left
        ) {
            throw new OverflowException(
                'Money addition overflow.'
            );
        }

        return $left + $right;
    }
}
