<?php

namespace App\Support\Pricing;

use InvalidArgumentException;

final readonly class TaxBreakdown
{
    public function __construct(
        public int $netMinor,
        public int $taxMinor,
        public int $grossMinor,
        public int $taxRateBps,
    ) {
        if (
            $netMinor < 0 ||
            $taxMinor < 0 ||
            $grossMinor < 0
        ) {
            throw new InvalidArgumentException(
                'Money breakdown cannot contain negative amounts.'
            );
        }

        if (
            $taxRateBps < 0 ||
            $taxRateBps > 10000
        ) {
            throw new InvalidArgumentException(
                'Tax rate must be between 0 and 10000 basis points.'
            );
        }

        if (
            $netMinor + $taxMinor !==
            $grossMinor
        ) {
            throw new InvalidArgumentException(
                'Money breakdown must satisfy net + tax = gross.'
            );
        }
    }
}
