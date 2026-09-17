<?php

namespace App\Support\Payment;

final class PaymentStatus
{
    public const PENDING = 'pending';

    public const AUTHORIZED = 'authorized';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    public static function all(): array
    {
        return [
            self::PENDING,
            self::AUTHORIZED,
            self::PAID,
            self::CANCELLED,
        ];
    }

    private function __construct() {}
}
