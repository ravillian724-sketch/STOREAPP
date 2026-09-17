<?php

namespace App\Support\Order;

final class OrderStatus
{
    public const PENDING = 'pending';

    public const CONFIRMED = 'confirmed';

    public const CANCELLED = 'cancelled';

    public static function all(): array
    {
        return [
            self::PENDING,
            self::CONFIRMED,
            self::CANCELLED,
        ];
    }

    private function __construct() {}
}
