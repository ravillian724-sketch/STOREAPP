<?php

namespace App\Exceptions\Payment;

use LogicException;

class PaymentIdempotencyConflictException extends LogicException
{
    public function __construct()
    {
        parent::__construct(
            'Payment idempotency key was reused with different request semantics.'
        );
    }
}
