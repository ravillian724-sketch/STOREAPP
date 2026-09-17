<?php

namespace App\Exceptions\Cart;

use RuntimeException;

final class CartNotAccessibleException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Cart is not accessible.'
        );
    }
}
