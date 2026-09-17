<?php

namespace App\Support\Payment;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

final readonly class VerifiedPaymentWebhook
{
    public string $providerCode;

    public string $providerEventId;

    public string $providerReference;

    public string $eventType;

    public CarbonImmutable $occurredAt;

    public function __construct(
        string $providerCode,
        string $providerEventId,
        string $providerReference,
        string $eventType,
        DateTimeInterface $occurredAt,
    ) {
        $this->providerCode =
            self::normalizeCode(
                $providerCode,
                'provider',
                64,
            );

        $this->providerEventId =
            self::normalizeIdentifier(
                $providerEventId,
                'provider event',
                191,
            );

        $this->providerReference =
            self::normalizeIdentifier(
                $providerReference,
                'provider reference',
                191,
            );

        $this->eventType =
            self::normalizeCode(
                $eventType,
                'event type',
                100,
            );

        $this->occurredAt =
            CarbonImmutable::instance(
                $occurredAt
            )->setMicrosecond(0);
    }

    private static function normalizeCode(
        string $value,
        string $field,
        int $maxLength,
    ): string {
        $value =
            mb_strtolower(
                trim(
                    $value
                )
            );

        if (
            $value === '' ||
            mb_strlen($value) > $maxLength ||
            preg_match(
                '/\A[a-z0-9][a-z0-9._-]*\z/',
                $value,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                "Invalid payment webhook {$field}."
            );
        }

        return $value;
    }

    private static function normalizeIdentifier(
        string $value,
        string $field,
        int $maxLength,
    ): string {
        $value =
            trim(
                $value
            );

        if (
            $value === '' ||
            mb_strlen($value) > $maxLength ||
            preg_match(
                '/[\x00-\x1F\x7F]/',
                $value,
            ) === 1
        ) {
            throw new InvalidArgumentException(
                "Invalid payment webhook {$field}."
            );
        }

        return $value;
    }
}
