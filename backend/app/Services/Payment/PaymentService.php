<?php

namespace App\Services\Payment;

use App\Exceptions\Payment\PaymentIdempotencyConflictException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Support\Payment\PaymentAttemptStatus;
use App\Support\Payment\PaymentStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class PaymentService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Materialize the single Payment obligation for an Order.
     *
     * Amount and currency are authoritative Order snapshots.
     * No caller-controlled financial values are accepted.
     */
    public function forOrder(
        Order $order,
    ): Payment {
        $tenantId =
            $this->tenantContext->requireId();

        if (
            (int) $order->tenant_id !==
            $tenantId
        ) {
            throw new LogicException(
                'Order must belong to the active tenant.'
            );
        }

        return DB::transaction(
            function () use (
                $order,
                $tenantId,
            ): Payment {
                /*
                 * Order is the parent aggregate boundary.
                 *
                 * Concurrent Payment materialization for
                 * the same Order therefore serializes here.
                 */
                $lockedOrder =
                    Order::query()
                        ->whereKey(
                            $order->id
                        )
                        ->lockForUpdate()
                        ->first();

                if (
                    $lockedOrder === null ||
                    (int) $lockedOrder->tenant_id !==
                        $tenantId
                ) {
                    throw new LogicException(
                        'Order is not accessible in the active tenant.'
                    );
                }

                if (
                    (int) $lockedOrder->total_minor <= 0
                ) {
                    throw new LogicException(
                        'Order does not have a positive payment obligation.'
                    );
                }

                $existing =
                    Payment::query()
                        ->where(
                            'order_id',
                            $lockedOrder->id,
                        )
                        ->lockForUpdate()
                        ->first();

                if ($existing !== null) {
                    $this->assertPaymentMatchesOrder(
                        $existing,
                        $lockedOrder,
                    );

                    return $existing;
                }

                return Payment::query()
                    ->create([
                        'order_id' => $lockedOrder->id,

                        'public_id' => (string) Str::uuid(),

                        'status' => PaymentStatus::PENDING,

                        'currency_code' => $lockedOrder
                            ->currency_code,

                        'amount_minor' => (int) $lockedOrder
                            ->total_minor,
                    ]);
            }
        );
    }

    /**
     * Create or replay one provider attempt.
     *
     * The caller chooses only internal provider/method codes
     * and the idempotency key.
     *
     * Currency and amount remain authoritative Payment data.
     */
    public function createAttempt(
        Payment $payment,
        string $idempotencyKey,
        string $providerCode,
        string $methodCode,
    ): PaymentAttempt {
        $tenantId =
            $this->tenantContext->requireId();

        if (
            (int) $payment->tenant_id !==
            $tenantId
        ) {
            throw new LogicException(
                'Payment must belong to the active tenant.'
            );
        }

        $idempotencyKey =
            $this->normalizeIdempotencyKey(
                $idempotencyKey
            );

        $providerCode =
            $this->normalizeCode(
                $providerCode,
                'provider',
            );

        $methodCode =
            $this->normalizeCode(
                $methodCode,
                'method',
            );

        return DB::transaction(
            function () use (
                $payment,
                $tenantId,
                $idempotencyKey,
                $providerCode,
                $methodCode,
            ): PaymentAttempt {
                /*
                 * Payment aggregate lock is deliberately
                 * first.
                 *
                 * Attempts for the same Payment therefore
                 * serialize before idempotency resolution.
                 */
                $lockedPayment =
                    Payment::query()
                        ->whereKey(
                            $payment->id
                        )
                        ->lockForUpdate()
                        ->first();

                if (
                    $lockedPayment === null ||
                    (int) $lockedPayment->tenant_id !==
                        $tenantId
                ) {
                    throw new LogicException(
                        'Payment is not accessible in the active tenant.'
                    );
                }

                /*
                 * Foundation currently defines the
                 * idempotency namespace at Tenant level.
                 *
                 * Reusing one key for another Payment is
                 * therefore a semantic conflict.
                 */
                $existing =
                    PaymentAttempt::query()
                        ->where(
                            'idempotency_key',
                            $idempotencyKey,
                        )
                        ->lockForUpdate()
                        ->first();

                if ($existing !== null) {
                    $this->assertAttemptMatchesRequest(
                        $existing,
                        $lockedPayment,
                        $providerCode,
                        $methodCode,
                    );

                    return $existing;
                }

                return PaymentAttempt::query()
                    ->create([
                        'payment_id' => $lockedPayment->id,

                        'public_id' => (string) Str::uuid(),

                        'idempotency_key' => $idempotencyKey,

                        'provider_code' => $providerCode,

                        'method_code' => $methodCode,

                        'status' => PaymentAttemptStatus::CREATED,

                        'currency_code' => $lockedPayment
                            ->currency_code,

                        'amount_minor' => (int) $lockedPayment
                            ->amount_minor,
                    ]);
            }
        );
    }

    private function assertPaymentMatchesOrder(
        Payment $payment,
        Order $order,
    ): void {
        if (
            (int) $payment->order_id !==
                (int) $order->id ||
            $payment->currency_code !==
                $order->currency_code ||
            (int) $payment->amount_minor !==
                (int) $order->total_minor
        ) {
            throw new LogicException(
                'Persisted payment no longer matches its Order snapshot.'
            );
        }
    }

    private function assertAttemptMatchesRequest(
        PaymentAttempt $attempt,
        Payment $payment,
        string $providerCode,
        string $methodCode,
    ): void {
        if (
            (int) $attempt->payment_id !==
                (int) $payment->id ||
            $attempt->provider_code !==
                $providerCode ||
            $attempt->method_code !==
                $methodCode ||
            $attempt->currency_code !==
                $payment->currency_code ||
            (int) $attempt->amount_minor !==
                (int) $payment->amount_minor
        ) {
            throw new PaymentIdempotencyConflictException;
        }
    }

    private function normalizeIdempotencyKey(
        string $key,
    ): string {
        $key =
            trim(
                $key
            );

        if (
            $key === '' ||
            mb_strlen($key) > 120
        ) {
            throw new InvalidArgumentException(
                'Invalid payment idempotency key.'
            );
        }

        return $key;
    }

    private function normalizeCode(
        string $code,
        string $field,
    ): string {
        $code =
            mb_strtolower(
                trim(
                    $code
                )
            );

        if (
            $code === '' ||
            mb_strlen($code) > 64 ||
            preg_match(
                '/\A[a-z0-9][a-z0-9._-]*\z/',
                $code,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                "Invalid payment {$field} code."
            );
        }

        return $code;
    }
}
