<?php

namespace App\Support\Pricing;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ShippingQuote
{
    public function __construct(
        public string $currencyCode,
        public TaxBreakdown $breakdown,
        public ?DateTimeImmutable $validUntil = null,
    ) {
        if (
            preg_match(
                '/^[A-Z]{3}$/',
                $currencyCode,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Shipping quote currency must be an uppercase ISO-style code.'
            );
        }
    }
}
