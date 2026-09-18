<?php

namespace App\Exceptions\Cart;

use RuntimeException;

final class CartBranchMismatchException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Cart inventory belongs to a different branch.'
        );
    }
}
