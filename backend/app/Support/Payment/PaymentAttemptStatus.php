<?php

namespace App\Support\Payment;

final class PaymentAttemptStatus
{
    public const CREATED = 'created';

    public const PENDING = 'pending';

    public const AUTHORIZED = 'authorized';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public static function all(): array
    {
        return [
            self::CREATED,
            self::PENDING,
            self::AUTHORIZED,
            self::SUCCEEDED,
            self::FAILED,
            self::CANCELLED,
        ];
    }

    private function __construct() {}
}
