<?php

namespace App\Services\Payment;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentWebhookReceipt;
use App\Support\Order\OrderStatus;
use App\Support\Payment\PaymentAttemptStatus;
use App\Support\Payment\PaymentStatus;
use App\Support\Payment\PaymentWebhookEventType;
use App\Support\Payment\PaymentWebhookProcessingOutcome;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

final class PaymentAttemptWebhookLifecycleService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function process(
        PaymentWebhookReceipt $receipt,
        DateTimeInterface $processedAt,
    ): PaymentAttempt {
        $tenantId =
            $this->tenantContext->requireId();

        if (
            (int) $receipt->tenant_id !==
            $tenantId
        ) {
            throw new LogicException(
                'Webhook receipt must belong to the active tenant.'
            );
        }

        if (
            $receipt->payment_attempt_id ===
            null
        ) {
            throw new LogicException(
                'Attempt lifecycle webhook is not correlated to an attempt.'
            );
        }

        if (
            ! in_array(
                $receipt->event_type,
                [
                    PaymentWebhookEventType::FAILED,
                    PaymentWebhookEventType::CANCELLED,
                ],
                true,
            )
        ) {
            throw new LogicException(
                'Webhook receipt is not a failure or cancellation event.'
            );
        }

        $instant =
            CarbonImmutable::instance(
                $processedAt
            )->setMicrosecond(0);

        $attemptSnapshot =
            PaymentAttempt::query()
                ->whereKey(
                    $receipt->payment_attempt_id
                )
                ->first();

        if ($attemptSnapshot === null) {
            throw new LogicException(
                'Correlated payment attempt is unavailable.'
            );
        }

        $paymentSnapshot =
            Payment::query()
                ->whereKey(
                    $attemptSnapshot->payment_id
                )
                ->first();

        if ($paymentSnapshot === null) {
            throw new LogicException(
                'Payment aggregate is unavailable.'
            );
        }

        $orderId =
            (int) $paymentSnapshot->order_id;

        $paymentId =
            (int) $paymentSnapshot->id;

        $attemptId =
            (int) $attemptSnapshot->id;

        $receiptId =
            (int) $receipt->id;

        return DB::transaction(
            function () use (
                $tenantId,
                $orderId,
                $paymentId,
                $attemptId,
                $receiptId,
                $instant,
            ): PaymentAttempt {
                /*
                 * Preserve the same global payment lock
                 * hierarchy used by PaymentSuccessService:
                 *
                 * Order
                 * -> Payment
                 * -> PaymentAttempt
                 * -> PaymentWebhookReceipt
                 *
                 * This avoids success/failure deadlocks.
                 */
                $order =
                    Order::query()
                        ->whereKey(
                            $orderId
                        )
                        ->lockForUpdate()
                        ->first();

                if (
                    $order === null ||
                    (int) $order->tenant_id !==
                        $tenantId
                ) {
                    throw new LogicException(
                        'Order is unavailable in the active tenant.'
                    );
                }

                $payment =
                    Payment::query()
                        ->whereKey(
                            $paymentId
                        )
                        ->where(
                            'order_id',
                            $order->id,
                        )
                        ->lockForUpdate()
                        ->first();

                if (
                    $payment === null ||
                    (int) $payment->tenant_id !==
                        $tenantId
                ) {
                    throw new LogicException(
                        'Payment no longer belongs to the locked order.'
                    );
                }

                $attempt =
                    PaymentAttempt::query()
                        ->whereKey(
                            $attemptId
                        )
                        ->where(
                            'payment_id',
                            $payment->id,
                        )
                        ->lockForUpdate()
                        ->first();

                if (
                    $attempt === null ||
                    (int) $attempt->tenant_id !==
                        $tenantId
                ) {
                    throw new LogicException(
                        'Payment attempt no longer belongs to the locked payment.'
                    );
                }

                $receipt =
                    PaymentWebhookReceipt::query()
                        ->whereKey(
                            $receiptId
                        )
                        ->where(
                            'payment_attempt_id',
                            $attempt->id,
                        )
                        ->lockForUpdate()
                        ->first();

                if (
                    $receipt === null ||
                    (int) $receipt->tenant_id !==
                        $tenantId
                ) {
                    throw new LogicException(
                        'Webhook receipt correlation changed.'
                    );
                }

                $this->assertEvidence(
                    $order,
                    $payment,
                    $attempt,
                    $receipt,
                    $instant,
                );

                if (
                    $receipt->processing_outcome !==
                        null &&
                    $receipt->processed_at ===
                        null
                ) {
                    throw new LogicException(
                        'Webhook processing outcome exists without processed timestamp.'
                    );
                }

                if (
                    $receipt->processed_at !==
                    null
                ) {
                    return $this
                        ->resolveProcessedReplay(
                            $attempt,
                            $receipt,
                        );
                }

                /*
                 * AUTHORIZED is deliberately conservative.
                 *
                 * A generic "failed" event after an
                 * authorization does not prove the
                 * authorization was voided.
                 *
                 * Likewise cancellation after authorization
                 * needs provider-specific void semantics
                 * before aggregate state can safely change.
                 */
                if (
                    $attempt->status ===
                    PaymentAttemptStatus::AUTHORIZED
                ) {
                    return $this->finalizeReceipt(
                        $attempt,
                        $receipt,
                        $instant,
                        PaymentWebhookProcessingOutcome::REQUIRES_RECONCILIATION,
                    );
                }

                /*
                 * Once money succeeded, a later failure or
                 * cancellation webhook must never downgrade
                 * the financial result.
                 */
                if (
                    $attempt->status ===
                    PaymentAttemptStatus::SUCCEEDED
                ) {
                    $outcome =
                        $payment->status ===
                            PaymentStatus::PAID &&
                        $order->status ===
                            OrderStatus::CONFIRMED
                            ? PaymentWebhookProcessingOutcome::IGNORED_TERMINAL
                            : PaymentWebhookProcessingOutcome::REQUIRES_RECONCILIATION;

                    return $this->finalizeReceipt(
                        $attempt,
                        $receipt,
                        $instant,
                        $outcome,
                    );
                }

                /*
                 * FAILED / CANCELLED attempts are immutable
                 * terminal results.
                 *
                 * Another terminal provider event does not
                 * rewrite the original outcome or timestamp.
                 */
                if (
                    in_array(
                        $attempt->status,
                        [
                            PaymentAttemptStatus::FAILED,
                            PaymentAttemptStatus::CANCELLED,
                        ],
                        true,
                    )
                ) {
                    $aggregateIsCoherent =
                        $this->aggregateCanCoexistWithTerminalAttempt(
                            $payment,
                            $order,
                            $attempt,
                        );

                    return $this->finalizeReceipt(
                        $attempt,
                        $receipt,
                        $instant,
                        $aggregateIsCoherent
                            ? PaymentWebhookProcessingOutcome::IGNORED_TERMINAL
                            : PaymentWebhookProcessingOutcome::REQUIRES_RECONCILIATION,
                    );
                }

                /*
                 * Only pre-authorization attempts can be
                 * terminalized by this generic policy.
                 */
                if (
                    ! in_array(
                        $attempt->status,
                        [
                            PaymentAttemptStatus::CREATED,
                            PaymentAttemptStatus::PENDING,
                        ],
                        true,
                    )
                ) {
                    return $this->finalizeReceipt(
                        $attempt,
                        $receipt,
                        $instant,
                        PaymentWebhookProcessingOutcome::REQUIRES_RECONCILIATION,
                    );
                }

                /*
                 * An attempt-level failure must not repair or
                 * reinterpret an already-terminal aggregate.
                 */
                if (
                    ! $this->aggregateCanCoexistWithTerminalAttempt(
                        $payment,
                        $order,
                        $attempt,
                    )
                ) {
                    return $this->finalizeReceipt(
                        $attempt,
                        $receipt,
                        $instant,
                        PaymentWebhookProcessingOutcome::REQUIRES_RECONCILIATION,
                    );
                }

                if (
                    $receipt->event_type ===
                    PaymentWebhookEventType::FAILED
                ) {
                    $attempt->status =
                        PaymentAttemptStatus::FAILED;

                    $attempt->failed_at =
                        $receipt->occurred_at;
                } else {
                    $attempt->status =
                        PaymentAttemptStatus::CANCELLED;

                    $attempt->cancelled_at =
                        $receipt->occurred_at;
                }

                $attempt->save();

                $receipt->processed_at =
                    $instant;

                $receipt->processing_outcome =
                    PaymentWebhookProcessingOutcome::APPLIED;

                $receipt->save();

                return $attempt->refresh();
            }
        );
    }

    private function assertEvidence(
        Order $order,
        Payment $payment,
        PaymentAttempt $attempt,
        PaymentWebhookReceipt $receipt,
        CarbonImmutable $instant,
    ): void {
        if (
            $attempt->provider_reference ===
                null ||
            $attempt->provider_code !==
                $receipt->provider_code ||
            $attempt->provider_reference !==
                $receipt->provider_reference
        ) {
            throw new LogicException(
                'Webhook provider identity does not match the payment attempt.'
            );
        }

        if (
            ! in_array(
                $receipt->event_type,
                [
                    PaymentWebhookEventType::FAILED,
                    PaymentWebhookEventType::CANCELLED,
                ],
                true,
            )
        ) {
            throw new LogicException(
                'Invalid attempt lifecycle webhook event.'
            );
        }

        if (
            $receipt->occurred_at ===
                null ||
            $receipt->received_at ===
                null
        ) {
            throw new LogicException(
                'Attempt lifecycle webhook has incomplete timing evidence.'
            );
        }

        if (
            $receipt->occurred_at
                ->greaterThan(
                    $instant
                ) ||
            $receipt->received_at
                ->greaterThan(
                    $instant
                )
        ) {
            throw new LogicException(
                'Attempt lifecycle webhook cannot be processed before it occurred or was received.'
            );
        }

        /*
         * Order is authoritative for payment money.
         */
        if (
            $payment->currency_code !==
                $order->currency_code ||
            (int) $payment->amount_minor !==
                (int) $order->total_minor ||
            $attempt->currency_code !==
                $payment->currency_code ||
            (int) $attempt->amount_minor !==
                (int) $payment->amount_minor
        ) {
            throw new LogicException(
                'Payment aggregate money snapshots are incoherent.'
            );
        }

        $hasAmount =
            $receipt->amount_minor !==
            null;

        $hasCurrency =
            $receipt->currency_code !==
            null;

        if ($hasAmount !== $hasCurrency) {
            throw new LogicException(
                'Webhook money evidence must contain both amount and currency or neither.'
            );
        }

        /*
         * Failure/cancellation providers are allowed to omit
         * money evidence.
         *
         * If they supply it, it must still match the
         * authoritative aggregate exactly.
         */
        if (
            $hasAmount &&
            (
                (int) $receipt->amount_minor !==
                    (int) $payment->amount_minor ||
                $receipt->currency_code !==
                    $payment->currency_code
            )
        ) {
            throw new LogicException(
                'Webhook money evidence does not match the payment aggregate.'
            );
        }
    }

    private function aggregateCanCoexistWithTerminalAttempt(
        Payment $payment,
        Order $order,
        PaymentAttempt $attempt,
    ): bool {
        /*
         * Payment is already locked by the caller.
         *
         * Every lifecycle mutation in this payment policy
         * serializes on Payment before changing attempt
         * status, so ordinary reads of sibling attempt
         * statuses are stable for this transaction.
         *
         * The current attempt is excluded because this
         * method determines whether the aggregate state is
         * explained by ANOTHER attempt.
         */
        $authorizedSiblingCount =
            PaymentAttempt::query()
                ->where(
                    'payment_id',
                    $payment->id,
                )
                ->where(
                    'id',
                    '<>',
                    $attempt->id,
                )
                ->where(
                    'status',
                    PaymentAttemptStatus::AUTHORIZED,
                )
                ->count();

        $succeededSiblingCount =
            PaymentAttempt::query()
                ->where(
                    'payment_id',
                    $payment->id,
                )
                ->where(
                    'id',
                    '<>',
                    $attempt->id,
                )
                ->where(
                    'status',
                    PaymentAttemptStatus::SUCCEEDED,
                )
                ->count();

        if (
            $payment->status ===
                PaymentStatus::PENDING &&
            $order->status ===
                OrderStatus::PENDING
        ) {
            return
                $authorizedSiblingCount === 0 &&
                $succeededSiblingCount === 0;
        }

        if (
            $payment->status ===
                PaymentStatus::AUTHORIZED &&
            $order->status ===
                OrderStatus::PENDING
        ) {
            return
                $authorizedSiblingCount === 1 &&
                $succeededSiblingCount === 0;
        }

        if (
            $payment->status ===
                PaymentStatus::PAID &&
            $order->status ===
                OrderStatus::CONFIRMED
        ) {
            return
                $authorizedSiblingCount === 0 &&
                $succeededSiblingCount === 1;
        }

        if (
            $payment->status ===
                PaymentStatus::CANCELLED &&
            $order->status ===
                OrderStatus::CANCELLED
        ) {
            return
                $authorizedSiblingCount === 0 &&
                $succeededSiblingCount === 0;
        }

        return false;
    }

    private function resolveProcessedReplay(
        PaymentAttempt $attempt,
        PaymentWebhookReceipt $receipt,
    ): PaymentAttempt {
        $outcome =
            $receipt->processing_outcome;

        if ($outcome === null) {
            throw new LogicException(
                'Processed attempt lifecycle receipt has no processing outcome.'
            );
        }

        if (
            $outcome ===
            PaymentWebhookProcessingOutcome::REQUIRES_RECONCILIATION
        ) {
            return $attempt;
        }

        if (
            $outcome ===
            PaymentWebhookProcessingOutcome::IGNORED_TERMINAL
        ) {
            if (
                ! in_array(
                    $attempt->status,
                    [
                        PaymentAttemptStatus::SUCCEEDED,
                        PaymentAttemptStatus::FAILED,
                        PaymentAttemptStatus::CANCELLED,
                    ],
                    true,
                )
            ) {
                throw new LogicException(
                    'Ignored terminal webhook no longer points to a terminal attempt.'
                );
            }

            return $attempt;
        }

        if (
            $outcome !==
            PaymentWebhookProcessingOutcome::APPLIED
        ) {
            throw new LogicException(
                'Unknown webhook processing outcome.'
            );
        }

        $expectedStatus =
            $receipt->event_type ===
                PaymentWebhookEventType::FAILED
                ? PaymentAttemptStatus::FAILED
                : PaymentAttemptStatus::CANCELLED;

        if (
            $attempt->status !==
            $expectedStatus
        ) {
            throw new LogicException(
                'Applied webhook replay no longer matches attempt terminal state.'
            );
        }

        $terminalAt =
            $expectedStatus ===
                PaymentAttemptStatus::FAILED
                ? $attempt->failed_at
                : $attempt->cancelled_at;

        if (
            $terminalAt === null ||
            $receipt->occurred_at === null ||
            $terminalAt->getTimestamp() !==
                $receipt->occurred_at
                    ->getTimestamp()
        ) {
            throw new LogicException(
                'Applied webhook replay no longer matches attempt terminal timestamp.'
            );
        }

        return $attempt;
    }

    private function finalizeReceipt(
        PaymentAttempt $attempt,
        PaymentWebhookReceipt $receipt,
        CarbonImmutable $instant,
        string $outcome,
    ): PaymentAttempt {
        $receipt->processed_at =
            $instant;

        $receipt->processing_outcome =
            $outcome;

        $receipt->save();

        return $attempt->refresh();
    }
}
