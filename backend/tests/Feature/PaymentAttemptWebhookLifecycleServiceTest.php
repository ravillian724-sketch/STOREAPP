<?php

namespace Tests\Feature;

use App\Models\AppInstance;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentWebhookReceipt;
use App\Models\Tenant;
use App\Services\Cart\CartService;
use App\Services\Payment\PaymentAttemptWebhookLifecycleService;
use App\Services\Payment\PaymentProviderReferenceService;
use App\Services\Payment\PaymentService;
use App\Support\Order\OrderStatus;
use App\Support\Payment\PaymentAttemptStatus;
use App\Support\Payment\PaymentStatus;
use App\Support\Payment\PaymentWebhookEventType;
use App\Support\Payment\PaymentWebhookProcessingOutcome;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class PaymentAttemptWebhookLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(
        string $name = 'Tenant A',
    ): Tenant {
        return Tenant::query()->create([
            'name_ar' => $name,
            'name_en' => $name,
            'country_code' => 'SA',
            'currency_code' => 'SAR',
            'vat_rate' => 15,
            'primary_color' => '#000000',
            'secondary_color' => '#FFFFFF',
            'is_active' => true,
        ]);
    }

    private function inTenant(
        Tenant $tenant,
        callable $callback,
    ): mixed {
        $context =
            app(TenantContext::class);

        $previous =
            $context->id();

        $context->set(
            $tenant->id
        );

        try {
            return $callback();
        } finally {
            if ($previous === null) {
                $context->clear();
            } else {
                $context->set(
                    $previous
                );
            }
        }
    }

    /**
     * @return array{
     * tenant: Tenant,
     * order: Order,
     * payment: Payment,
     * attempt: PaymentAttempt,
     * at: CarbonImmutable
     * }
     */
    private function fixture(): array
    {
        $tenant =
            $this->tenant();

        $instance =
            AppInstance::query()->create([
                'tenant_id' => $tenant->id,
                'channel' => 'mobile',
                'is_active' => true,
            ]);

        $at =
            CarbonImmutable::now()
                ->startOfSecond();

        return $this->inTenant(
            $tenant,
            function () use (
                $tenant,
                $instance,
                $at,
            ): array {
                $cart =
                    app(
                        CartService::class
                    )->create(
                        $instance,
                        $at->addDay(),
                    )->cart;

                $order =
                    Order::query()->create([
                        'app_instance_id' => $instance->id,

                        'cart_id' => $cart->id,

                        'public_id' => (string) Str::uuid(),

                        'status' => OrderStatus::PENDING,

                        'currency_code' => 'SAR',

                        'subtotal_minor' => 1000,

                        'discount_minor' => 0,

                        'tax_minor' => 150,

                        'shipping_minor' => 0,

                        'total_minor' => 1150,
                    ]);

                $payment =
                    app(
                        PaymentService::class
                    )->forOrder(
                        $order
                    );

                $attempt =
                    app(
                        PaymentService::class
                    )->createAttempt(
                        $payment,
                        'attempt-lifecycle-001',
                        'gateway_card',
                        'mada',
                    );

                $attempt =
                    app(
                        PaymentProviderReferenceService::class
                    )->bind(
                        $attempt,
                        'provider-attempt-lifecycle-001',
                    );

                return [
                    'tenant' => $tenant,
                    'order' => $order,
                    'payment' => $payment,
                    'attempt' => $attempt,
                    'at' => $at,
                ];
            },
        );
    }

    private function receipt(
        array $fixture,
        string $eventType,
        string $eventId,
        ?CarbonImmutable $occurredAt = null,
        ?int $amountMinor = null,
        ?string $currencyCode = null,
    ): PaymentWebhookReceipt {
        $occurredAt ??=
            $fixture['at']
                ->addSecond();

        return $this->inTenant(
            $fixture['tenant'],
            fn (): PaymentWebhookReceipt => PaymentWebhookReceipt::query()
                ->create([
                    'payment_attempt_id' => $fixture['attempt']->id,

                    'public_id' => (string) Str::uuid(),

                    'provider_code' => 'gateway_card',

                    'provider_event_id' => $eventId,

                    'provider_reference' => 'provider-attempt-lifecycle-001',

                    'event_type' => $eventType,

                    'amount_minor' => $amountMinor,

                    'currency_code' => $currencyCode,

                    'payload_sha256' => hash(
                        'sha256',
                        $eventId,
                    ),

                    'occurred_at' => $occurredAt,

                    'received_at' => $occurredAt
                        ->addSecond(),
                ]),
        );
    }

    public function test_failed_attempt_does_not_cancel_payment_or_order_and_allows_retry(): void
    {
        $fixture =
            $this->fixture();

        $receipt =
            $this->receipt(
                $fixture,
                PaymentWebhookEventType::FAILED,
                'event-failed-001',
            );

        $this->inTenant(
            $fixture['tenant'],
            function () use (
                $fixture,
                $receipt,
            ): void {
                $attempt =
                    app(
                        PaymentAttemptWebhookLifecycleService::class
                    )->process(
                        $receipt,
                        $fixture['at']
                            ->addSeconds(3),
                    );

                $this->assertSame(
                    PaymentAttemptStatus::FAILED,
                    $attempt->status,
                );

                $this->assertNotNull(
                    $attempt->failed_at
                );

                $this->assertSame(
                    PaymentStatus::PENDING,
                    $fixture['payment']
                        ->refresh()
                        ->status,
                );

                $this->assertSame(
                    OrderStatus::PENDING,
                    $fixture['order']
                        ->refresh()
                        ->status,
                );

                $this->assertSame(
                    PaymentWebhookProcessingOutcome::APPLIED,
                    $receipt->refresh()
                        ->processing_outcome,
                );

                $retry =
                    app(
                        PaymentService::class
                    )->createAttempt(
                        $fixture['payment']
                            ->refresh(),
                        'attempt-lifecycle-retry',
                        'gateway_card',
                        'visa',
                    );

                $this->assertSame(
                    PaymentAttemptStatus::CREATED,
                    $retry->status,
                );

                $this->assertNotSame(
                    $attempt->id,
                    $retry->id,
                );
            },
        );
    }

    public function test_cancelled_attempt_does_not_cancel_payment_or_order(): void
    {
        $fixture =
            $this->fixture();

        $receipt =
            $this->receipt(
                $fixture,
                PaymentWebhookEventType::CANCELLED,
                'event-cancelled-001',
            );

        $this->inTenant(
            $fixture['tenant'],
            function () use (
                $fixture,
                $receipt,
            ): void {
                $attempt =
                    app(
                        PaymentAttemptWebhookLifecycleService::class
                    )->process(
                        $receipt,
                        $fixture['at']
                            ->addSeconds(3),
                    );

                $this->assertSame(
                    PaymentAttemptStatus::CANCELLED,
                    $attempt->status,
                );

                $this->assertNotNull(
                    $attempt->cancelled_at
                );

                $this->assertSame(
                    PaymentStatus::PENDING,
                    $fixture['payment']
                        ->refresh()
                        ->status,
                );

                $this->assertSame(
                    OrderStatus::PENDING,
                    $fixture['order']
                        ->refresh()
                        ->status,
                );
            },
        );
    }

    public function test_same_failure_receipt_replays_without_rewriting_timestamps(): void
    {
        $fixture =
            $this->fixture();

        $receipt =
            $this->receipt(
                $fixture,
                PaymentWebhookEventType::FAILED,
                'event-failed-replay',
            );

        $this->inTenant(
            $fixture['tenant'],
            function () use (
                $fixture,
                $receipt,
            ): void {
                $service =
                    app(
                        PaymentAttemptWebhookLifecycleService::class
                    );

                $first =
                    $service->process(
                        $receipt,
                        $fixture['at']
                            ->addSeconds(3),
                    );

                $firstFailedAt =
                    $first->failed_at;

                $firstProcessedAt =
                    $receipt->refresh()
                        ->processed_at;

                $second =
                    $service->process(
                        $receipt->refresh(),
                        $fixture['at']
                            ->addMinute(),
                    );

                $this->assertTrue(
                    $second->failed_at
                        ->equalTo(
                            $firstFailedAt
                        )
                );

                $this->assertTrue(
                    $receipt->refresh()
                        ->processed_at
                        ->equalTo(
                            $firstProcessedAt
                        )
                );

                $this->assertSame(
                    PaymentWebhookProcessingOutcome::APPLIED,
                    $receipt
                        ->processing_outcome,
                );
            },
        );
    }

    public function test_second_terminal_event_after_failure_is_ignored_without_rewrite(): void
    {
        $fixture =
            $this->fixture();

        $failed =
            $this->receipt(
                $fixture,
                PaymentWebhookEventType::FAILED,
                'event-first-failure',
            );

        $this->inTenant(
            $fixture['tenant'],
            fn () => app(
                PaymentAttemptWebhookLifecycleService::class
            )->process(
                $failed,
                $fixture['at']
                    ->addSeconds(3),
            ),
        );

        $originalFailedAt =
            $fixture['attempt']
                ->refresh()
                ->failed_at;

        $cancelled =
            $this->receipt(
                $fixture,
                PaymentWebhookEventType::CANCELLED,
                'event-late-cancel',
                $fixture['at']
                    ->addSeconds(4),
            );

        $this->inTenant(
            $fixture['tenant'],
            function () use (
                $fixture,
                $cancelled,
                $originalFailedAt,
            ): void {
                $attempt =
                    app(
                        PaymentAttemptWebhookLifecycleService::class
                    )->process(
                        $cancelled,
                        $fixture['at']
                            ->addSeconds(6),
                    );

                $this->assertSame(
                    PaymentAttemptStatus::FAILED,
                    $attempt->status,
                );

                $this->assertTrue(
                    $attempt->failed_at
                        ->equalTo(
                            $originalFailedAt
                        )
                );

                $this->assertNull(
                    $attempt->cancelled_at
                );

                $this->assertSame(
                    PaymentWebhookProcessingOutcome::IGNORED_TERMINAL,
                    $cancelled->refresh()
                        ->processing_outcome,
                );
            },
        );
    }

    public function test_late_failure_after_success_is_ignored_without_downgrading_payment(): void
    {
        $fixture =
            $this->fixture();

        $this->inTenant(
            $fixture['tenant'],
            function () use (
                $fixture
            ): void {
                $settledAt =
                    $fixture['at']
                        ->addSecond();

                $attempt =
                    $fixture['attempt'];

                $attempt->status =
                    PaymentAttemptStatus::SUCCEEDED;

                $attempt->succeeded_at =
                    $settledAt;

                $attempt->save();

                $payment =
                    $fixture['payment'];

                $payment->status =
                    PaymentStatus::PAID;

                $payment->paid_at =
                    $settledAt;

                $payment->save();

                $order =
                    $fixture['order'];

                $order->status =
                    OrderStatus::CONFIRMED;

                $order->confirmed_at =
                    $settledAt;

                $order->save();
            },
        );

        $receipt =
            $this->receipt(
                $fixture,
                PaymentWebhookEventType::FAILED,
                'event-late-failure-after-success',
                $fixture['at']
                    ->addSeconds(3),
            );

        $this->inTenant(
            $fixture['tenant'],
            function () use (
                $fixture,
                $receipt,
            ): void {
                $attempt =
                    app(
                        PaymentAttemptWebhookLifecycleService::class
                    )->process(
                        $receipt,
                        $fixture['at']
                            ->addSeconds(5),
                    );

                $this->assertSame(
                    PaymentAttemptStatus::SUCCEEDED,
                    $attempt->status,
                );

                $this->assertSame(
                    PaymentStatus::PAID,
                    $fixture['payment']
                        ->refresh()
                        ->status,
                );

                $this->assertSame(
                    OrderStatus::CONFIRMED,
                    $fixture['order']
                        ->refresh()
                        ->status,
                );

                $this->assertSame(
                    PaymentWebhookProcessingOutcome::IGNORED_TERMINAL,
                    $receipt->refresh()
                        ->processing_outcome,
                );
            },
        );
    }

    public function test_failure_after_authorization_requires_reconciliation_without_downgrade(): void
    {
        $fixture =
            $this->fixture();

        $this->inTenant(
            $fixture['tenant'],
            function () use (
                $fixture
            ): void {
                $authorizedAt =
                    $fixture['at']
                        ->addSecond();

                $attempt =
                    $fixture['attempt'];

                $attempt->status =
                    PaymentAttemptStatus::AUTHORIZED;

                $attempt->authorized_at =
                    $authorizedAt;

                $attempt->save();

                $payment =
                    $fixture['payment'];

                $payment->status =
                    PaymentStatus::AUTHORIZED;

                $payment->authorized_at =
                    $authorizedAt;

                $payment->save();
            },
        );

        $receipt =
            $this->receipt(
                $fixture,
                PaymentWebhookEventType::FAILED,
                'event-failure-after-auth',
                $fixture['at']
                    ->addSeconds(2),
            );

        $this->inTenant(
            $fixture['tenant'],
            function () use (
                $fixture,
                $receipt,
            ): void {
                $attempt =
                    app(
                        PaymentAttemptWebhookLifecycleService::class
                    )->process(
                        $receipt,
                        $fixture['at']
                            ->addSeconds(4),
                    );

                $this->assertSame(
                    PaymentAttemptStatus::AUTHORIZED,
                    $attempt->status,
                );

                $this->assertSame(
                    PaymentStatus::AUTHORIZED,
                    $fixture['payment']
                        ->refresh()
                        ->status,
                );

                $this->assertSame(
                    OrderStatus::PENDING,
                    $fixture['order']
                        ->refresh()
                        ->status,
                );

                $this->assertSame(
                    PaymentWebhookProcessingOutcome::REQUIRES_RECONCILIATION,
                    $receipt->refresh()
                        ->processing_outcome,
                );
            },
        );
    }

    public function test_optional_money_evidence_must_match_payment(): void
    {
        $fixture =
            $this->fixture();

        $receipt =
            $this->receipt(
                $fixture,
                PaymentWebhookEventType::FAILED,
                'event-wrong-failure-money',
                amountMinor: 1149,
                currencyCode: 'SAR',
            );

        $this->inTenant(
            $fixture['tenant'],
            function () use (
                $fixture,
                $receipt,
            ): void {
                try {
                    app(
                        PaymentAttemptWebhookLifecycleService::class
                    )->process(
                        $receipt,
                        $fixture['at']
                            ->addSeconds(3),
                    );

                    $this->fail(
                        'Expected webhook money mismatch rejection.'
                    );
                } catch (LogicException) {
                    $this->addToAssertionCount(
                        1
                    );
                }

                $this->assertSame(
                    PaymentAttemptStatus::CREATED,
                    $fixture['attempt']
                        ->refresh()
                        ->status,
                );

                $this->assertNull(
                    $receipt->refresh()
                        ->processed_at
                );

                $this->assertNull(
                    $receipt
                        ->processing_outcome
                );
            },
        );
    }

    public function test_foreign_tenant_cannot_process_attempt_lifecycle_webhook(): void
    {
        $fixture =
            $this->fixture();

        $receipt =
            $this->receipt(
                $fixture,
                PaymentWebhookEventType::FAILED,
                'event-foreign-tenant',
            );

        $foreign =
            $this->tenant(
                'Tenant B'
            );

        $this->expectException(
            LogicException::class
        );

        $this->inTenant(
            $foreign,
            fn () => app(
                PaymentAttemptWebhookLifecycleService::class
            )->process(
                $receipt,
                $fixture['at']
                    ->addSeconds(3),
            ),
        );
    }

    private function receiptForAttempt(
        array $fixture,
        PaymentAttempt $attempt,
        string $eventType,
        string $eventId,
        CarbonImmutable $occurredAt,
    ): PaymentWebhookReceipt {
        if (
            $attempt->provider_reference ===
            null
        ) {
            throw new LogicException(
                'Test attempt requires provider reference.'
            );
        }

        return $this->inTenant(
            $fixture['tenant'],
            fn (): PaymentWebhookReceipt => PaymentWebhookReceipt::query()
                ->create([
                    'payment_attempt_id' => $attempt->id,

                    'public_id' => (string) Str::uuid(),

                    'provider_code' => $attempt->provider_code,

                    'provider_event_id' => $eventId,

                    'provider_reference' => $attempt->provider_reference,

                    'event_type' => $eventType,

                    'payload_sha256' => hash(
                        'sha256',
                        $eventId,
                    ),

                    'occurred_at' => $occurredAt,

                    'received_at' => $occurredAt
                        ->addSecond(),
                ]),
        );
    }

    public function test_failure_on_loser_attempt_after_other_attempt_paid_is_applied(): void
    {
        $fixture =
            $this->fixture();

        $loser =
            $this->inTenant(
                $fixture['tenant'],
                function () use (
                    $fixture
                ): PaymentAttempt {
                    $attempt =
                        app(
                            PaymentService::class
                        )->createAttempt(
                            $fixture['payment'],
                            'attempt-loser-paid',
                            'gateway_card',
                            'visa',
                        );

                    return app(
                        PaymentProviderReferenceService::class
                    )->bind(
                        $attempt,
                        'provider-loser-paid',
                    );
                },
            );

        $this->inTenant(
            $fixture['tenant'],
            function () use (
                $fixture
            ): void {
                $settledAt =
                    $fixture['at']
                        ->addSecond();

                $winner =
                    $fixture['attempt'];

                $winner->status =
                    PaymentAttemptStatus::SUCCEEDED;

                $winner->succeeded_at =
                    $settledAt;

                $winner->save();

                $payment =
                    $fixture['payment'];

                $payment->status =
                    PaymentStatus::PAID;

                $payment->paid_at =
                    $settledAt;

                $payment->save();

                $order =
                    $fixture['order'];

                $order->status =
                    OrderStatus::CONFIRMED;

                $order->confirmed_at =
                    $settledAt;

                $order->save();
            },
        );

        $receipt =
            $this->receiptForAttempt(
                $fixture,
                $loser,
                PaymentWebhookEventType::FAILED,
                'event-loser-failed-after-paid',
                $fixture['at']
                    ->addSeconds(3),
            );

        $this->inTenant(
            $fixture['tenant'],
            function () use (
                $fixture,
                $receipt,
            ): void {
                $attempt =
                    app(
                        PaymentAttemptWebhookLifecycleService::class
                    )->process(
                        $receipt,
                        $fixture['at']
                            ->addSeconds(5),
                    );

                $this->assertSame(
                    PaymentAttemptStatus::FAILED,
                    $attempt->status,
                );

                $this->assertSame(
                    PaymentStatus::PAID,
                    $fixture['payment']
                        ->refresh()
                        ->status,
                );

                $this->assertSame(
                    OrderStatus::CONFIRMED,
                    $fixture['order']
                        ->refresh()
                        ->status,
                );

                $this->assertSame(
                    PaymentWebhookProcessingOutcome::APPLIED,
                    $receipt->refresh()
                        ->processing_outcome,
                );
            },
        );
    }

    public function test_failure_on_loser_attempt_while_other_attempt_authorized_is_applied(): void
    {
        $fixture =
            $this->fixture();

        $loser =
            $this->inTenant(
                $fixture['tenant'],
                function () use (
                    $fixture
                ): PaymentAttempt {
                    $attempt =
                        app(
                            PaymentService::class
                        )->createAttempt(
                            $fixture['payment'],
                            'attempt-loser-authorized',
                            'gateway_card',
                            'visa',
                        );

                    return app(
                        PaymentProviderReferenceService::class
                    )->bind(
                        $attempt,
                        'provider-loser-authorized',
                    );
                },
            );

        $this->inTenant(
            $fixture['tenant'],
            function () use (
                $fixture
            ): void {
                $authorizedAt =
                    $fixture['at']
                        ->addSecond();

                $winner =
                    $fixture['attempt'];

                $winner->status =
                    PaymentAttemptStatus::AUTHORIZED;

                $winner->authorized_at =
                    $authorizedAt;

                $winner->save();

                $payment =
                    $fixture['payment'];

                $payment->status =
                    PaymentStatus::AUTHORIZED;

                $payment->authorized_at =
                    $authorizedAt;

                $payment->save();
            },
        );

        $receipt =
            $this->receiptForAttempt(
                $fixture,
                $loser,
                PaymentWebhookEventType::FAILED,
                'event-loser-failed-after-authorization',
                $fixture['at']
                    ->addSeconds(3),
            );

        $this->inTenant(
            $fixture['tenant'],
            function () use (
                $fixture,
                $receipt,
            ): void {
                $attempt =
                    app(
                        PaymentAttemptWebhookLifecycleService::class
                    )->process(
                        $receipt,
                        $fixture['at']
                            ->addSeconds(5),
                    );

                $this->assertSame(
                    PaymentAttemptStatus::FAILED,
                    $attempt->status,
                );

                $this->assertSame(
                    PaymentStatus::AUTHORIZED,
                    $fixture['payment']
                        ->refresh()
                        ->status,
                );

                $this->assertSame(
                    OrderStatus::PENDING,
                    $fixture['order']
                        ->refresh()
                        ->status,
                );

                $this->assertSame(
                    PaymentWebhookProcessingOutcome::APPLIED,
                    $receipt->refresh()
                        ->processing_outcome,
                );
            },
        );
    }

    public function test_late_terminal_event_on_failed_attempt_after_other_attempt_paid_is_ignored_not_reconciled(): void
    {
        $fixture =
            $this->fixture();

        $failedReceipt =
            $this->receipt(
                $fixture,
                PaymentWebhookEventType::FAILED,
                'event-first-failure-before-retry-success',
            );

        $this->inTenant(
            $fixture['tenant'],
            fn () => app(
                PaymentAttemptWebhookLifecycleService::class
            )->process(
                $failedReceipt,
                $fixture['at']
                    ->addSeconds(3),
            ),
        );

        $winner =
            $this->inTenant(
                $fixture['tenant'],
                function () use (
                    $fixture
                ): PaymentAttempt {
                    $attempt =
                        app(
                            PaymentService::class
                        )->createAttempt(
                            $fixture['payment']
                                ->refresh(),
                            'attempt-winner-after-failure',
                            'gateway_card',
                            'visa',
                        );

                    return app(
                        PaymentProviderReferenceService::class
                    )->bind(
                        $attempt,
                        'provider-winner-after-failure',
                    );
                },
            );

        $this->inTenant(
            $fixture['tenant'],
            function () use (
                $fixture,
                $winner
            ): void {
                $settledAt =
                    $fixture['at']
                        ->addSeconds(4);

                $winner->status =
                    PaymentAttemptStatus::SUCCEEDED;

                $winner->succeeded_at =
                    $settledAt;

                $winner->save();

                $payment =
                    $fixture['payment']
                        ->refresh();

                $payment->status =
                    PaymentStatus::PAID;

                $payment->paid_at =
                    $settledAt;

                $payment->save();

                $order =
                    $fixture['order'];

                $order->status =
                    OrderStatus::CONFIRMED;

                $order->confirmed_at =
                    $settledAt;

                $order->save();
            },
        );

        $lateReceipt =
            $this->receipt(
                $fixture,
                PaymentWebhookEventType::CANCELLED,
                'event-late-cancel-after-other-success',
                $fixture['at']
                    ->addSeconds(6),
            );

        $this->inTenant(
            $fixture['tenant'],
            function () use (
                $fixture,
                $lateReceipt,
            ): void {
                $attempt =
                    app(
                        PaymentAttemptWebhookLifecycleService::class
                    )->process(
                        $lateReceipt,
                        $fixture['at']
                            ->addSeconds(8),
                    );

                $this->assertSame(
                    PaymentAttemptStatus::FAILED,
                    $attempt->status,
                );

                $this->assertSame(
                    PaymentStatus::PAID,
                    $fixture['payment']
                        ->refresh()
                        ->status,
                );

                $this->assertSame(
                    OrderStatus::CONFIRMED,
                    $fixture['order']
                        ->refresh()
                        ->status,
                );

                $this->assertSame(
                    PaymentWebhookProcessingOutcome::IGNORED_TERMINAL,
                    $lateReceipt->refresh()
                        ->processing_outcome,
                );
            },
        );
    }

    public function test_multiple_succeeded_siblings_force_reconciliation_instead_of_hiding_double_success(): void
    {
        $fixture =
            $this->fixture();

        $loser =
            $this->inTenant(
                $fixture['tenant'],
                function () use (
                    $fixture
                ): PaymentAttempt {
                    $attempt =
                        app(
                            PaymentService::class
                        )->createAttempt(
                            $fixture['payment'],
                            'attempt-double-success-guard-loser',
                            'gateway_card',
                            'visa',
                        );

                    return app(
                        PaymentProviderReferenceService::class
                    )->bind(
                        $attempt,
                        'provider-double-success-guard-loser',
                    );
                },
            );

        $secondWinner =
            $this->inTenant(
                $fixture['tenant'],
                function () use (
                    $fixture
                ): PaymentAttempt {
                    $attempt =
                        app(
                            PaymentService::class
                        )->createAttempt(
                            $fixture['payment'],
                            'attempt-double-success-guard-winner',
                            'gateway_card',
                            'apple_pay',
                        );

                    return app(
                        PaymentProviderReferenceService::class
                    )->bind(
                        $attempt,
                        'provider-double-success-guard-winner',
                    );
                },
            );

        $this->inTenant(
            $fixture['tenant'],
            function () use (
                $fixture,
                $secondWinner,
            ): void {
                $settledAt =
                    $fixture['at']
                        ->addSecond();

                foreach (
                    [
                        $fixture['attempt'],
                        $secondWinner,
                    ] as $winner
                ) {
                    $winner->status =
                        PaymentAttemptStatus::SUCCEEDED;

                    $winner->succeeded_at =
                        $settledAt;

                    $winner->save();
                }

                $payment =
                    $fixture['payment'];

                $payment->status =
                    PaymentStatus::PAID;

                $payment->paid_at =
                    $settledAt;

                $payment->save();

                $order =
                    $fixture['order'];

                $order->status =
                    OrderStatus::CONFIRMED;

                $order->confirmed_at =
                    $settledAt;

                $order->save();
            },
        );

        $receipt =
            $this->receiptForAttempt(
                $fixture,
                $loser,
                PaymentWebhookEventType::FAILED,
                'event-double-success-guard',
                $fixture['at']
                    ->addSeconds(3),
            );

        $this->inTenant(
            $fixture['tenant'],
            function () use (

                $receipt,
                $fixture,
            ): void {
                $attempt =
                    app(
                        PaymentAttemptWebhookLifecycleService::class
                    )->process(
                        $receipt,
                        $fixture['at']
                            ->addSeconds(5),
                    );

                $this->assertSame(
                    PaymentAttemptStatus::CREATED,
                    $attempt->status,
                );

                $this->assertSame(
                    PaymentWebhookProcessingOutcome::REQUIRES_RECONCILIATION,
                    $receipt->refresh()
                        ->processing_outcome,
                );
            },
        );
    }
}
