<?php

namespace App\Exceptions\Cart;

use RuntimeException;

final class CartSkuUnavailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'SKU is unavailable for storefront cart operations.'
        );
    }
}
