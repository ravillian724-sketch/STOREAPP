<?php

namespace App\Support\Inventory;

final class InventoryMovementType
{
    public const OPENING = 'opening';

    public const RECEIPT = 'receipt';

    public const SHIPMENT = 'shipment';

    public const CUSTOMER_RETURN = 'customer_return';

    public const SUPPLIER_RETURN = 'supplier_return';

    public const ADJUSTMENT = 'adjustment';

    public const TRANSFER_IN = 'transfer_in';

    public const TRANSFER_OUT = 'transfer_out';

    public static function all(): array
    {
        return [
            self::OPENING,
            self::RECEIPT,
            self::SHIPMENT,
            self::CUSTOMER_RETURN,
            self::SUPPLIER_RETURN,
            self::ADJUSTMENT,
            self::TRANSFER_IN,
            self::TRANSFER_OUT,
        ];
    }
}
