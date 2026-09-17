<?php

namespace App\Support\Cart;

final class CartStatus
{
    public const ACTIVE = 'active';

    public const CONVERTED = 'converted';

    public const ABANDONED = 'abandoned';

    public const EXPIRED = 'expired';

    public static function all(): array
    {
        return [
            self::ACTIVE,
            self::CONVERTED,
            self::ABANDONED,
            self::EXPIRED,
        ];
    }

    private function __construct() {}
}
