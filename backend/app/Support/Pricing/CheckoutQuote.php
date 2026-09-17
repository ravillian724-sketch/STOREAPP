<?php

namespace App\Support\Pricing;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OverflowException;

final readonly class CheckoutQuote
{
    public int $subtotalMinor;

    public int $discountMinor;

    public int $taxMinor;

    public int $shippingMinor;

    public int $totalMinor;

    /**
     * @param  list<QuoteLine>  $lines
     */
    public function __construct(
        public string $cartPublicId,
        public string $currencyCode,
        public DateTimeImmutable $quotedAt,
        public DateTimeImmutable $expiresAt,
        public array $lines,
        public TaxBreakdown $shipping,
    ) {
        if (
            ! Str::isUuid(
                $cartPublicId
            )
        ) {
            throw new InvalidArgumentException(
                'Quote cart identifier must be a UUID.'
            );
        }

        if (
            preg_match(
                '/^[A-Z]{3}$/',
                $currencyCode,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Quote currency must be an uppercase ISO-style code.'
            );
        }

        if (
            $expiresAt <= $quotedAt
        ) {
            throw new InvalidArgumentException(
                'Quote expiration must be after quote creation.'
            );
        }

        if (
            $lines === [] ||
            ! array_is_list($lines)
        ) {
            throw new InvalidArgumentException(
                'Quote must contain a non-empty list of lines.'
            );
        }

        $subtotal = 0;
        $discount = 0;
        $lineTax = 0;

        $seenCartItems = [];

        foreach ($lines as $line) {
            if (
                ! $line instanceof QuoteLine
            ) {
                throw new InvalidArgumentException(
                    'Quote contains an invalid line value.'
                );
            }

            if (
                $line->currencyCode !==
                $currencyCode
            ) {
                throw new InvalidArgumentException(
                    'Every quote line must use the quote currency.'
                );
            }

            if (
                isset(
                    $seenCartItems[
                        $line->cartItemPublicId
                    ]
                )
            ) {
                throw new InvalidArgumentException(
                    'Quote cannot contain the same cart item twice.'
                );
            }

            $seenCartItems[
                $line->cartItemPublicId
            ] = true;

            $subtotal =
                $this->addSafely(
                    $subtotal,
                    $line
                        ->lineSubtotalMinor,
                );

            $discount =
                $this->addSafely(
                    $discount,
                    $line
                        ->discountMinor,
                );

            $lineTax =
                $this->addSafely(
                    $lineTax,
                    $line
                        ->taxMinor,
                );
        }

        if (
            $discount > $subtotal
        ) {
            throw new InvalidArgumentException(
                'Quote discount cannot exceed subtotal.'
            );
        }

        /*
         * shippingMinor is the net shipping charge.
         *
         * Shipping VAT belongs in taxMinor so that the
         * persisted Order equation remains:
         *
         * subtotal
         * - discount
         * + tax
         * + shipping
         * = total
         */
        $tax =
            $this->addSafely(
                $lineTax,
                $shipping->taxMinor,
            );

        $afterDiscount =
            $subtotal - $discount;

        $beforeShipping =
            $this->addSafely(
                $afterDiscount,
                $tax,
            );

        $total =
            $this->addSafely(
                $beforeShipping,
                $shipping->netMinor,
            );

        $this->subtotalMinor =
            $subtotal;

        $this->discountMinor =
            $discount;

        $this->taxMinor =
            $tax;

        $this->shippingMinor =
            $shipping->netMinor;

        $this->totalMinor =
            $total;

        /*
         * Independent coherence proof:
         *
         * Sum(line totals) + shipping gross
         * must equal the derived order total.
         */
        $lineTotals = 0;

        foreach ($lines as $line) {
            $lineTotals =
                $this->addSafely(
                    $lineTotals,
                    $line->lineTotalMinor,
                );
        }

        $coherentTotal =
            $this->addSafely(
                $lineTotals,
                $shipping->grossMinor,
            );

        if (
            $coherentTotal !==
            $this->totalMinor
        ) {
            throw new InvalidArgumentException(
                'Quote totals are internally inconsistent.'
            );
        }
    }

    public function isUsableAt(
        DateTimeInterface $at,
    ): bool {
        $instant =
            DateTimeImmutable::createFromInterface(
                $at
            );

        return
            $instant >= $this->quotedAt &&
            $instant < $this->expiresAt;
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
                'Quote money addition overflow.'
            );
        }

        return $left + $right;
    }
}
