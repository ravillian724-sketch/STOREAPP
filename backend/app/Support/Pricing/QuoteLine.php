<?php

namespace App\Support\Pricing;

use Illuminate\Support\Str;
use InvalidArgumentException;
use OverflowException;

final readonly class QuoteLine
{
    public function __construct(
        public string $cartItemPublicId,
        public int $skuId,
        public int $locationId,
        public string $skuPricePublicId,
        public string $currencyCode,
        public int $quantity,
        public int $unitNetMinor,
        public int $lineSubtotalMinor,
        public int $discountMinor,
        public int $taxRateBps,
        public int $taxMinor,
        public int $lineTotalMinor,
    ) {
        if (
            ! Str::isUuid(
                $cartItemPublicId
            )
        ) {
            throw new InvalidArgumentException(
                'Quote line cart item identifier must be a UUID.'
            );
        }

        if (
            ! Str::isUuid(
                $skuPricePublicId
            )
        ) {
            throw new InvalidArgumentException(
                'Quote line price identifier must be a UUID.'
            );
        }

        if (
            $skuId <= 0 ||
            $locationId <= 0
        ) {
            throw new InvalidArgumentException(
                'Quote line entity identifiers must be positive.'
            );
        }

        if (
            preg_match(
                '/^[A-Z]{3}$/',
                $currencyCode,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Quote line currency must be an uppercase ISO-style code.'
            );
        }

        if ($quantity <= 0) {
            throw new InvalidArgumentException(
                'Quote line quantity must be positive.'
            );
        }

        if (
            $unitNetMinor < 0 ||
            $lineSubtotalMinor < 0 ||
            $discountMinor < 0 ||
            $taxMinor < 0 ||
            $lineTotalMinor < 0
        ) {
            throw new InvalidArgumentException(
                'Quote line money values cannot be negative.'
            );
        }

        if (
            $taxRateBps < 0 ||
            $taxRateBps > 10000
        ) {
            throw new InvalidArgumentException(
                'Quote line tax rate must be between 0 and 10000 basis points.'
            );
        }

        if (
            $discountMinor >
            $lineSubtotalMinor
        ) {
            throw new InvalidArgumentException(
                'Quote line discount cannot exceed subtotal.'
            );
        }

        $expectedTotal =
            $this->addSafely(
                $lineSubtotalMinor
                    - $discountMinor,
                $taxMinor,
            );

        if (
            $expectedTotal !==
            $lineTotalMinor
        ) {
            throw new InvalidArgumentException(
                'Quote line must satisfy subtotal - discount + tax = total.'
            );
        }
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
                'Quote arithmetic accepts only non-negative integers.'
            );
        }

        if (
            $right >
            PHP_INT_MAX - $left
        ) {
            throw new OverflowException(
                'Quote line money addition overflow.'
            );
        }

        return $left + $right;
    }
}
