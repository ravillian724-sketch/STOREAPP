<?php

namespace App\Support\Payment;

final class PaymentWebhookEventType
{
    public const AUTHORIZED =
        'payment.authorized';

    public const SUCCEEDED =
        'payment.succeeded';

    public const FAILED =
        'payment.failed';

    public const CANCELLED =
        'payment.cancelled';

    public static function all(): array
    {
        return [
            self::AUTHORIZED,
            self::SUCCEEDED,
            self::FAILED,
            self::CANCELLED,
        ];
    }

    private function __construct() {}
}
