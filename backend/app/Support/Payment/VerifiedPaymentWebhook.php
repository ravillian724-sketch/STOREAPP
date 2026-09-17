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

    public ?int $amountMinor;

    public ?string $currencyCode;

    public function __construct(
        string $providerCode,
        string $providerEventId,
        string $providerReference,
        string $eventType,
        DateTimeInterface $occurredAt,
        ?int $amountMinor = null,
        ?string $currencyCode = null,
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
            self::normalizeEventType(
                $eventType
            );

        $this->occurredAt =
            CarbonImmutable::instance(
                $occurredAt
            )->setMicrosecond(0);

        if (
            ($amountMinor === null) !==
            ($currencyCode === null)
        ) {
            throw new InvalidArgumentException(
                'Webhook money evidence must contain both amount and currency or neither.'
            );
        }

        if ($amountMinor !== null) {
            if ($amountMinor <= 0) {
                throw new InvalidArgumentException(
                    'Webhook payment amount must be positive.'
                );
            }

            $currencyCode =
                strtoupper(
                    trim(
                        $currencyCode
                    )
                );

            if (
                preg_match(
                    '/\A[A-Z]{3}\z/',
                    $currencyCode,
                ) !== 1
            ) {
                throw new InvalidArgumentException(
                    'Invalid webhook currency code.'
                );
            }
        }

        $this->amountMinor =
            $amountMinor;

        $this->currencyCode =
            $currencyCode;
    }

    private static function normalizeEventType(
        string $value,
    ): string {
        $value =
            mb_strtolower(
                trim(
                    $value
                )
            );

        if (
            ! in_array(
                $value,
                PaymentWebhookEventType::all(),
                true,
            )
        ) {
            throw new InvalidArgumentException(
                'Payment webhook event type must already be normalized to a canonical lifecycle event.'
            );
        }

        return $value;
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
