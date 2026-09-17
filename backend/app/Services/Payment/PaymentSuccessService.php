<?php

namespace App\Services\Payment;

use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentWebhookReceipt;
use App\Services\Inventory\InventoryAvailabilityService;
use App\Support\Inventory\InventoryReservationStatus;
use App\Support\Order\OrderStatus;
use App\Support\Payment\PaymentAttemptStatus;
use App\Support\Payment\PaymentStatus;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

final class PaymentSuccessService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly InventoryAvailabilityService $availability,
    ) {}

    public function confirm(
        PaymentWebhookReceipt $receipt,
        DateTimeInterface $processedAt,
    ): Payment {
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
                'Payment success webhook is not correlated to an attempt.'
            );
        }

        if (
            $receipt->event_type !==
            'payment.succeeded'
        ) {
            throw new LogicException(
                'Webhook receipt is not a canonical payment success event.'
            );
        }

        if (
            $receipt->amount_minor === null ||
            $receipt->currency_code === null
        ) {
            throw new LogicException(
                'Payment success requires authenticated amount and currency evidence.'
            );
        }

        if (
            $receipt->occurred_at === null ||
            $receipt->received_at === null
        ) {
            throw new LogicException(
                'Payment success receipt has incomplete timing evidence.'
            );
        }

        $instant =
            CarbonImmutable::instance(
                $processedAt
            )->setMicrosecond(0);

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
                'Payment success cannot be processed before it occurred or was received.'
            );
        }

        /*
         * Snapshot immutable identities only.
         *
         * These reads decide which rows must be locked.
         * All semantic decisions are repeated after locks.
         */
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
            ): Payment {
                /*
                 * GLOBAL LOCK ORDER:
                 *
                 * Order
                 * -> Payment
                 * -> PaymentAttempt
                 * -> PaymentWebhookReceipt
                 * -> OrderItems
                 * -> InventoryPosition
                 * -> InventoryReservation
                 *
                 * PaymentService::forOrder() already locks
                 * Order -> Payment, so success MUST preserve
                 * that order to avoid a real deadlock.
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

                if ($attempt === null) {
                    throw new LogicException(
                        'Payment attempt no longer belongs to the payment.'
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

                if ($receipt === null) {
                    throw new LogicException(
                        'Webhook receipt correlation changed.'
                    );
                }

                $this->assertProviderIdentity(
                    $attempt,
                    $receipt,
                );

                $this->assertMoneyCoherence(
                    $order,
                    $payment,
                    $attempt,
                    $receipt,
                );

                $items =
                    OrderItem::query()
                        ->where(
                            'order_id',
                            $order->id,
                        )
                        ->orderBy(
                            'location_id'
                        )
                        ->orderBy(
                            'sku_id'
                        )
                        ->orderBy(
                            'id'
                        )
                        ->lockForUpdate()
                        ->get();

                if ($items->isEmpty()) {
                    throw new LogicException(
                        'Paid order cannot be empty.'
                    );
                }

                $alreadySettled =
                    $attempt->status ===
                        PaymentAttemptStatus::SUCCEEDED &&
                    $payment->status ===
                        PaymentStatus::PAID &&
                    $order->status ===
                        OrderStatus::CONFIRMED;

                $partiallySettled =
                    $attempt->status ===
                        PaymentAttemptStatus::SUCCEEDED ||
                    $payment->status ===
                        PaymentStatus::PAID ||
                    $order->status ===
                        OrderStatus::CONFIRMED;

                /*
                 * processed_at is completion evidence.
                 *
                 * It may not exist while the aggregate is
                 * still unsettled.
                 */
                if (
                    $receipt->processed_at !== null &&
                    ! $alreadySettled
                ) {
                    throw new LogicException(
                        'Webhook receipt is marked processed but the payment aggregate is not settled.'
                    );
                }

                if (
                    $partiallySettled &&
                    ! $alreadySettled
                ) {
                    throw new LogicException(
                        'Payment success aggregate is partially settled and requires reconciliation.'
                    );
                }

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
                    throw new LogicException(
                        'A terminal failed or cancelled attempt cannot become successful.'
                    );
                }

                if (
                    $payment->status ===
                    PaymentStatus::CANCELLED
                ) {
                    throw new LogicException(
                        'Cancelled payment cannot become paid.'
                    );
                }

                if (
                    $order->status ===
                    OrderStatus::CANCELLED
                ) {
                    throw new LogicException(
                        'Cancelled order cannot be confirmed by payment success.'
                    );
                }

                /*
                 * Idempotent replay.
                 */
                if ($alreadySettled) {
                    $this->assertCommittedReservations(
                        $items
                    );

                    if (
                        $receipt->processed_at ===
                        null
                    ) {
                        $receipt->processed_at =
                            $instant;

                        $receipt->save();
                    }

                    return $payment->refresh();
                }

                if (
                    ! in_array(
                        $payment->status,
                        [
                            PaymentStatus::PENDING,
                            PaymentStatus::AUTHORIZED,
                        ],
                        true,
                    )
                ) {
                    throw new LogicException(
                        'Payment is not eligible for success.'
                    );
                }

                if (
                    $order->status !==
                    OrderStatus::PENDING
                ) {
                    throw new LogicException(
                        'Only a pending order can be confirmed by first payment success.'
                    );
                }

                /*
                 * Commit inventory BEFORE money/order states.
                 *
                 * No stock ledger movement occurs here.
                 */
                $this->commitReservations(
                    $items,
                    $instant,
                );

                $attempt->status =
                    PaymentAttemptStatus::SUCCEEDED;

                $attempt->succeeded_at =
                    $receipt->occurred_at;

                $attempt->failure_code =
                    null;

                $attempt->failure_message =
                    null;

                $attempt->save();

                $payment->status =
                    PaymentStatus::PAID;

                $payment->paid_at =
                    $receipt->occurred_at;

                $payment->save();

                $order->status =
                    OrderStatus::CONFIRMED;

                $order->confirmed_at =
                    $instant;

                $order->save();

                $receipt->processed_at =
                    $instant;

                $receipt->save();

                return $payment->refresh();
            }
        );
    }

    private function assertProviderIdentity(
        PaymentAttempt $attempt,
        PaymentWebhookReceipt $receipt,
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
            $receipt->event_type !==
            'payment.succeeded'
        ) {
            throw new LogicException(
                'Webhook is not a payment success event.'
            );
        }
    }

    private function assertMoneyCoherence(
        Order $order,
        Payment $payment,
        PaymentAttempt $attempt,
        PaymentWebhookReceipt $receipt,
    ): void {
        $currency =
            $order->currency_code;

        $amount =
            (int) $order->total_minor;

        if (
            $payment->currency_code !== $currency ||
            (int) $payment->amount_minor !== $amount ||
            $attempt->currency_code !== $currency ||
            (int) $attempt->amount_minor !== $amount ||
            $receipt->currency_code !== $currency ||
            (int) $receipt->amount_minor !== $amount
        ) {
            throw new LogicException(
                'Payment success money evidence does not match the authoritative order.'
            );
        }
    }

    /**
     * @param  Collection<int, OrderItem>  $items
     */
    private function commitReservations(
        Collection $items,
        CarbonImmutable $instant,
    ): void {
        foreach ($items as $item) {
            /*
             * InventoryPosition is the serialization point
             * for every operation affecting ATS.
             *
             * Without this lock a hold could expire, another
             * transaction could consume its newly available
             * stock, and payment success could then wrongly
             * resurrect the old hold.
             */
            $this->lockInventoryPosition(
                $item
            );

            $reservations =
                InventoryReservation::query()
                    ->where(
                        'reference_type',
                        'order_item',
                    )
                    ->where(
                        'reference_id',
                        $item->public_id,
                    )
                    ->where(
                        'status',
                        InventoryReservationStatus::ACTIVE,
                    )
                    ->lockForUpdate()
                    ->get();

            if ($reservations->count() !== 1) {
                throw new LogicException(
                    'Order item must own exactly one active reservation.'
                );
            }

            $reservation =
                $reservations->first();

            $this->assertReservationMatchesItem(
                $reservation,
                $item,
            );

            if (
                $reservation->expires_at !==
                null
            ) {
                /*
                 * Business-time proof:
                 * the hold must cover the requested
                 * processing instant.
                 */
                if (
                    ! $reservation->expires_at
                        ->greaterThan(
                            $instant
                        )
                ) {
                    throw new LogicException(
                        'Order inventory reservation expired before payment success could be committed.'
                    );
                }

                /*
                 * Concurrency-time proof:
                 *
                 * We acquired InventoryPosition above.
                 * The hold must STILL be live now that the
                 * inventory serialization lock is ours.
                 *
                 * This prevents resurrecting stock that
                 * became sellable while this transaction
                 * waited for locks.
                 */
                $lockObservedAt =
                    CarbonImmutable::now();

                if (
                    ! $reservation->expires_at
                        ->greaterThan(
                            $lockObservedAt
                        )
                ) {
                    throw new LogicException(
                        'Order inventory reservation expired while payment success waited for the inventory lock.'
                    );
                }
            }

            /*
             * ACTIVE + NULL expires_at = committed stock.
             *
             * It remains deducted from ATS but has not yet
             * left physical inventory.
             */
            $reservation->expires_at =
                null;

            $reservation->save();
        }
    }

    /**
     * @param  Collection<int, OrderItem>  $items
     */
    private function assertCommittedReservations(
        Collection $items,
    ): void {
        foreach ($items as $item) {
            $this->lockInventoryPosition(
                $item
            );

            $reservations =
                InventoryReservation::query()
                    ->where(
                        'reference_type',
                        'order_item',
                    )
                    ->where(
                        'reference_id',
                        $item->public_id,
                    )
                    ->where(
                        'status',
                        InventoryReservationStatus::ACTIVE,
                    )
                    ->lockForUpdate()
                    ->get();

            if ($reservations->count() !== 1) {
                throw new LogicException(
                    'Confirmed order lost its committed reservation.'
                );
            }

            $reservation =
                $reservations->first();

            $this->assertReservationMatchesItem(
                $reservation,
                $item,
            );

            if (
                $reservation->expires_at !==
                null
            ) {
                throw new LogicException(
                    'Confirmed order reservation is not coherently committed.'
                );
            }
        }
    }

    private function lockInventoryPosition(
        OrderItem $item,
    ): void {
        $sku =
            $item->sku()
                ->first();

        $location =
            $item->location()
                ->first();

        if (
            $sku === null ||
            $location === null
        ) {
            throw new LogicException(
                'Order inventory references are unavailable.'
            );
        }

        $this->availability
            ->lockPosition(
                $sku,
                $location,
            );
    }

    private function assertReservationMatchesItem(
        InventoryReservation $reservation,
        OrderItem $item,
    ): void {
        if (
            (int) $reservation->sku_id !==
                (int) $item->sku_id ||
            (int) $reservation->location_id !==
                (int) $item->location_id ||
            (int) $reservation->quantity !==
                (int) $item->quantity
        ) {
            throw new LogicException(
                'Order reservation does not match the order item.'
            );
        }
    }
}
