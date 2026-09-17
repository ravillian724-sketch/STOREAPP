<?php

namespace App\Exceptions\Cart;

use RuntimeException;

final class CartNotMutableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Cart cannot be modified.'
        );
    }
}
