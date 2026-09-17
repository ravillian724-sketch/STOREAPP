<?php

namespace App\Support\Payment;

final class PaymentWebhookProcessingOutcome
{
    public const APPLIED =
        'applied';

    public const IGNORED_TERMINAL =
        'ignored_terminal';

    public static function all(): array
    {
        return [
            self::APPLIED,
            self::IGNORED_TERMINAL,
        ];
    }

    private function __construct() {}
}
