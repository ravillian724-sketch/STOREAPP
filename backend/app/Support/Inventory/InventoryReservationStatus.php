<?php

namespace App\Support\Inventory;

final class InventoryReservationStatus
{
    public const ACTIVE = 'active';

    public const RELEASED = 'released';

    public const CONSUMED = 'consumed';

    public const EXPIRED = 'expired';

    public static function all(): array
    {
        return [
            self::ACTIVE,
            self::RELEASED,
            self::CONSUMED,
            self::EXPIRED,
        ];
    }

    private function __construct() {}
}
