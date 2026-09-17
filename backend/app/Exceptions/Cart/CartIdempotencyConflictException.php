<?php

namespace App\Exceptions\Cart;

use RuntimeException;

final class CartIdempotencyConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Idempotency key was already used for a different cart mutation.'
        );
    }
}
