<?php

namespace App\Support\Order;

use InvalidArgumentException;

final readonly class OrderCheckoutSnapshot
{
    public ?string $customerName;

    public ?string $customerPhone;

    public ?string $customerEmail;

    public ?array $shippingAddressSnapshot;

    public function __construct(
        ?string $customerName = null,
        ?string $customerPhone = null,
        ?string $customerEmail = null,
        ?array $shippingAddressSnapshot = null,
    ) {
        $this->customerName =
            $this->normalize(
                $customerName,
                200,
                'customer name',
            );

        $this->customerPhone =
            $this->normalize(
                $customerPhone,
                50,
                'customer phone',
            );

        $this->customerEmail =
            $this->normalize(
                $customerEmail,
                254,
                'customer email',
            );

        if (
            $this->customerEmail !== null &&
            filter_var(
                $this->customerEmail,
                FILTER_VALIDATE_EMAIL,
            ) === false
        ) {
            throw new InvalidArgumentException(
                'Customer email is invalid.'
            );
        }

        if (
            $shippingAddressSnapshot !== null
        ) {
            $this->assertJsonSafe(
                $shippingAddressSnapshot
            );
        }

        $this->shippingAddressSnapshot =
            $shippingAddressSnapshot;
    }

    private function normalize(
        ?string $value,
        int $maximumLength,
        string $field,
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value =
            trim(
                $value
            );

        if ($value === '') {
            return null;
        }

        if (
            mb_strlen($value) >
            $maximumLength
        ) {
            throw new InvalidArgumentException(
                ucfirst($field).
                ' exceeds its maximum length.'
            );
        }

        return $value;
    }

    private function assertJsonSafe(
        mixed $value,
    ): void {
        if (
            $value === null ||
            is_string($value) ||
            is_int($value) ||
            is_float($value) ||
            is_bool($value)
        ) {
            return;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException(
                'Shipping address snapshot must contain JSON-safe values only.'
            );
        }

        foreach (
            $value as $key => $child
        ) {
            if (
                ! is_int($key) &&
                ! is_string($key)
            ) {
                throw new InvalidArgumentException(
                    'Shipping address snapshot contains an invalid key.'
                );
            }

            $this->assertJsonSafe(
                $child
            );
        }
    }
}
