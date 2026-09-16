<?php

namespace App\Exceptions\Inventory;

use RuntimeException;

final class InsufficientAvailableStockException extends RuntimeException
{
    public function __construct(
        public readonly int $requestedQuantity,
        public readonly int $availableQuantity,
    ) {
        parent::__construct(
            'Insufficient available stock. '.
            "Requested={$requestedQuantity}, ".
            "Available={$availableQuantity}."
        );
    }
}
