<?php

namespace App\Exceptions\Cart;

use RuntimeException;

final class CartItemNotFoundException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Cart item was not found.'
        );
    }
}
