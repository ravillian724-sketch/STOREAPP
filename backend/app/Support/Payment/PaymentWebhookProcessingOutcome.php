<?php

namespace App\Support\Payment;

final class PaymentWebhookProcessingOutcome
{
    public const APPLIED =
        'applied';

    public const IGNORED_TERMINAL =
        'ignored_terminal';

    public const REQUIRES_RECONCILIATION =
        'requires_reconciliation';

    public static function all(): array
    {
        return [
            self::APPLIED,
            self::IGNORED_TERMINAL,
            self::REQUIRES_RECONCILIATION,
        ];
    }

    private function __construct() {}
}
