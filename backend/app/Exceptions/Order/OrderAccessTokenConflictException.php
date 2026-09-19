<?php

namespace App\Exceptions\Order;

use RuntimeException;

final class OrderAccessTokenConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Order access token does not match the existing order.'
        );
    }
}
