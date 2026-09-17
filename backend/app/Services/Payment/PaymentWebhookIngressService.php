<?php

namespace App\Services\Payment;

use App\Contracts\Payment\PaymentProviderWebhookVerifier;
use App\Exceptions\Payment\PaymentWebhookReplayConflictException;
use App\Models\PaymentAttempt;
use App\Models\PaymentWebhookReceipt;
use App\Support\Payment\VerifiedPaymentWebhook;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class PaymentWebhookIngressService
{
    private const MAX_RAW_BODY_BYTES =
        1_048_576;

    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function ingest(
        PaymentProviderWebhookVerifier $verifier,
        string $rawBody,
        array $headers,
        DateTimeInterface $receivedAt,
    ): PaymentWebhookReceipt {
        $this->tenantContext->requireId();

        if (
            $rawBody === '' ||
            strlen($rawBody) >
                self::MAX_RAW_BODY_BYTES
        ) {
            throw new InvalidArgumentException(
                'Invalid payment webhook payload size.'
            );
        }

        $verifierProvider =
            $this->normalizeProviderCode(
                $verifier->providerCode()
            );

        /*
         * No database mutation occurs before authentication.
         *
         * Provider implementation must verify its signature
         * before returning this typed value.
         */
        $event =
            $verifier->verify(
                $rawBody,
                $headers,
            );

        if (
            $event->providerCode !==
            $verifierProvider
        ) {
            throw new LogicException(
                'Webhook verifier provider mismatch.'
            );
        }

        /*
         * Persist only a one-way fingerprint of the exact
         * raw provider payload.
         *
         * Raw payload and signature headers are deliberately
         * excluded from persistence.
         */
        $payloadSha256 =
            hash(
                'sha256',
                $rawBody,
            );

        $receivedAt =
            CarbonImmutable::instance(
                $receivedAt
            )->setMicrosecond(0);

        return DB::transaction(
            function () use (
                $event,
                $payloadSha256,
                $receivedAt,
            ): PaymentWebhookReceipt {
                /*
                 * Resolve provider identity to an existing
                 * attempt if possible.
                 *
                 * Webhooks are allowed to arrive before the
                 * provider reference is locally bound.
                 */
                $attempt =
                    PaymentAttempt::query()
                        ->where(
                            'provider_code',
                            $event->providerCode,
                        )
                        ->where(
                            'provider_reference',
                            $event->providerReference,
                        )
                        ->lockForUpdate()
                        ->first();

                $existing =
                    PaymentWebhookReceipt::query()
                        ->where(
                            'provider_code',
                            $event->providerCode,
                        )
                        ->where(
                            'provider_event_id',
                            $event->providerEventId,
                        )
                        ->lockForUpdate()
                        ->first();

                if ($existing !== null) {
                    $this->assertReplayMatches(
                        $existing,
                        $event,
                        $payloadSha256,
                        $attempt,
                    );

                    if (
                        $existing
                            ->payment_attempt_id ===
                            null &&
                        $attempt !== null
                    ) {
                        $existing
                            ->payment_attempt_id =
                            $attempt->id;

                        $existing->save();
                    }

                    return $existing->refresh();
                }

                return PaymentWebhookReceipt::query()
                    ->create([
                        'payment_attempt_id' => $attempt?->id,

                        'public_id' => (string) Str::uuid(),

                        'provider_code' => $event->providerCode,

                        'provider_event_id' => $event->providerEventId,

                        'provider_reference' => $event->providerReference,

                        'event_type' => $event->eventType,

                        'amount_minor' => $event->amountMinor,

                        'currency_code' => $event->currencyCode,

                        'payload_sha256' => $payloadSha256,

                        'occurred_at' => $event->occurredAt,

                        'received_at' => $receivedAt,
                    ]);
            }
        );
    }

    private function assertReplayMatches(
        PaymentWebhookReceipt $receipt,
        VerifiedPaymentWebhook $event,
        string $payloadSha256,
        ?PaymentAttempt $attempt,
    ): void {
        $attemptMismatch =
            $receipt->payment_attempt_id !== null &&
            $attempt !== null &&
            (int) $receipt->payment_attempt_id !==
                (int) $attempt->id;

        if (
            $receipt->provider_reference !==
                $event->providerReference ||
            $receipt->event_type !==
                $event->eventType ||
            (
                $receipt->amount_minor === null
                    ? $event->amountMinor !== null
                    : (int) $receipt->amount_minor !==
                        $event->amountMinor
            ) ||
            $receipt->currency_code !==
                $event->currencyCode ||
            $receipt->payload_sha256 !==
                $payloadSha256 ||
            $receipt->occurred_at === null ||
            $receipt->occurred_at
                ->getTimestamp() !==
                $event->occurredAt
                    ->getTimestamp() ||
            $attemptMismatch
        ) {
            throw new PaymentWebhookReplayConflictException;
        }
    }

    private function normalizeProviderCode(
        string $providerCode,
    ): string {
        $providerCode =
            mb_strtolower(
                trim(
                    $providerCode
                )
            );

        if (
            $providerCode === '' ||
            mb_strlen($providerCode) > 64 ||
            preg_match(
                '/\A[a-z0-9][a-z0-9._-]*\z/',
                $providerCode,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Invalid webhook verifier provider code.'
            );
        }

        return $providerCode;
    }
}
