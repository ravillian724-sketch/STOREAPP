<?php

namespace App\Exceptions\Order;

use RuntimeException;

final class OrderNotAccessibleException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Order is not accessible.'
        );
    }
}
