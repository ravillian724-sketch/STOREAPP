<?php

namespace App\Exceptions\Payment;

use LogicException;

class PaymentWebhookReplayConflictException extends LogicException
{
    public function __construct()
    {
        parent::__construct(
            'Provider webhook event identity was replayed with different semantics.'
        );
    }
}
