<?php

namespace Tests\Feature;

use App\Exceptions\Payment\PaymentIdempotencyConflictException;
use App\Models\AppInstance;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Tenant;
use App\Services\Cart\CartService;
use App\Services\Payment\PaymentService;
use App\Support\Order\OrderStatus;
use App\Support\Payment\PaymentAttemptStatus;
use App\Support\Payment\PaymentStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class PaymentIdempotencyServiceTest extends TestCase
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

    private function order(
        Tenant $tenant,
        int $totalMinor = 1150,
    ): Order {
        $instance =
            AppInstance::query()->create([
                'tenant_id' => $tenant->id,

                'channel' => 'mobile',

                'is_active' => true,
            ]);

        $cart =
            $this->inTenant(
                $tenant,
                fn (): Cart => app(
                    CartService::class
                )->create(
                    $instance,
                    now()->addDay(),
                )->cart,
            );

        /*
         * Keep the persisted Order equation valid:
         *
         * subtotal + tax = total.
         */
        $taxMinor =
            $totalMinor >= 150
                ? 150
                : 0;

        $subtotalMinor =
            $totalMinor - $taxMinor;

        return $this->inTenant(
            $tenant,
            fn (): Order => Order::query()->create([
                'app_instance_id' => $instance->id,

                'cart_id' => $cart->id,

                'public_id' => (string) Str::uuid(),

                'status' => OrderStatus::PENDING,

                'currency_code' => 'SAR',

                'subtotal_minor' => $subtotalMinor,

                'discount_minor' => 0,

                'tax_minor' => $taxMinor,

                'shipping_minor' => 0,

                'total_minor' => $totalMinor,
            ]),
        );
    }

    public function test_payment_materialization_uses_authoritative_order_money_and_replays_same_aggregate(): void
    {
        $tenant =
            $this->tenant();

        $order =
            $this->order(
                $tenant,
                2875,
            );

        $first =
            $this->inTenant(
                $tenant,
                fn (): Payment => app(
                    PaymentService::class
                )->forOrder(
                    $order
                ),
            );

        $replay =
            $this->inTenant(
                $tenant,
                fn (): Payment => app(
                    PaymentService::class
                )->forOrder(
                    $order
                ),
            );

        $this->assertSame(
            $first->id,
            $replay->id,
        );

        $this->assertSame(
            PaymentStatus::PENDING,
            $first->status,
        );

        $this->assertSame(
            'SAR',
            $first->currency_code,
        );

        $this->assertSame(
            2875,
            $first->amount_minor,
        );

        $this->inTenant(
            $tenant,
            fn () => $this->assertSame(
                1,
                Payment::query()->count(),
            ),
        );
    }

    public function test_payment_materialization_rejects_foreign_tenant_order(): void
    {
        $tenantA =
            $this->tenant(
                'Tenant A'
            );

        $tenantB =
            $this->tenant(
                'Tenant B'
            );

        $orderB =
            $this->order(
                $tenantB
            );

        $this->expectException(
            LogicException::class
        );

        $this->inTenant(
            $tenantA,
            fn () => app(
                PaymentService::class
            )->forOrder(
                $orderB
            ),
        );
    }

    public function test_attempt_uses_authoritative_payment_money_and_normalizes_codes(): void
    {
        $tenant =
            $this->tenant();

        $order =
            $this->order(
                $tenant,
                2875,
            );

        $payment =
            $this->inTenant(
                $tenant,
                fn (): Payment => app(
                    PaymentService::class
                )->forOrder(
                    $order
                ),
            );

        $attempt =
            $this->inTenant(
                $tenant,
                fn (): PaymentAttempt => app(
                    PaymentService::class
                )->createAttempt(
                    $payment,
                    'payment-attempt-001',
                    ' GATEWAY_CARD ',
                    ' APPLE_PAY ',
                ),
            );

        $this->assertSame(
            PaymentAttemptStatus::CREATED,
            $attempt->status,
        );

        $this->assertSame(
            'gateway_card',
            $attempt->provider_code,
        );

        $this->assertSame(
            'apple_pay',
            $attempt->method_code,
        );

        $this->assertSame(
            'SAR',
            $attempt->currency_code,
        );

        $this->assertSame(
            2875,
            $attempt->amount_minor,
        );
    }

    public function test_same_attempt_request_replays_same_row(): void
    {
        $tenant =
            $this->tenant();

        $order =
            $this->order(
                $tenant
            );

        $payment =
            $this->inTenant(
                $tenant,
                fn (): Payment => app(
                    PaymentService::class
                )->forOrder(
                    $order
                ),
            );

        $first =
            $this->inTenant(
                $tenant,
                fn (): PaymentAttempt => app(
                    PaymentService::class
                )->createAttempt(
                    $payment,
                    'same-attempt-key',
                    'gateway_card',
                    'mada',
                ),
            );

        $replay =
            $this->inTenant(
                $tenant,
                fn (): PaymentAttempt => app(
                    PaymentService::class
                )->createAttempt(
                    $payment,
                    'same-attempt-key',
                    'gateway_card',
                    'mada',
                ),
            );

        $this->assertSame(
            $first->id,
            $replay->id,
        );

        $this->inTenant(
            $tenant,
            fn () => $this->assertSame(
                1,
                PaymentAttempt::query()
                    ->count(),
            ),
        );
    }

    public function test_same_idempotency_key_with_changed_method_is_rejected_without_second_attempt(): void
    {
        $tenant =
            $this->tenant();

        $order =
            $this->order(
                $tenant
            );

        $payment =
            $this->inTenant(
                $tenant,
                fn (): Payment => app(
                    PaymentService::class
                )->forOrder(
                    $order
                ),
            );

        $this->inTenant(
            $tenant,
            fn () => app(
                PaymentService::class
            )->createAttempt(
                $payment,
                'immutable-attempt',
                'gateway_card',
                'mada',
            ),
        );

        try {
            $this->inTenant(
                $tenant,
                fn () => app(
                    PaymentService::class
                )->createAttempt(
                    $payment,
                    'immutable-attempt',
                    'gateway_card',
                    'visa',
                ),
            );

            $this->fail(
                'Expected idempotency conflict.'
            );
        } catch (
            PaymentIdempotencyConflictException
        ) {
            $this->addToAssertionCount(
                1
            );
        }

        $this->inTenant(
            $tenant,
            fn () => $this->assertSame(
                1,
                PaymentAttempt::query()
                    ->count(),
            ),
        );
    }

    public function test_same_tenant_idempotency_key_cannot_be_reused_for_another_payment(): void
    {
        $tenant =
            $this->tenant();

        $orderA =
            $this->order(
                $tenant,
                1150,
            );

        $orderB =
            $this->order(
                $tenant,
                2300,
            );

        $service =
            app(
                PaymentService::class
            );

        $paymentA =
            $this->inTenant(
                $tenant,
                fn (): Payment => $service->forOrder(
                    $orderA
                ),
            );

        $paymentB =
            $this->inTenant(
                $tenant,
                fn (): Payment => $service->forOrder(
                    $orderB
                ),
            );

        $this->inTenant(
            $tenant,
            fn () => $service->createAttempt(
                $paymentA,
                'tenant-wide-key',
                'gateway_card',
                'mada',
            ),
        );

        try {
            $this->inTenant(
                $tenant,
                fn () => $service->createAttempt(
                    $paymentB,
                    'tenant-wide-key',
                    'gateway_card',
                    'mada',
                ),
            );

            $this->fail(
                'Expected cross-payment idempotency conflict.'
            );
        } catch (
            PaymentIdempotencyConflictException
        ) {
            $this->addToAssertionCount(
                1
            );
        }

        $this->inTenant(
            $tenant,
            fn () => $this->assertSame(
                1,
                PaymentAttempt::query()
                    ->count(),
            ),
        );
    }

    public function test_different_idempotency_keys_allow_multiple_attempts_for_same_payment(): void
    {
        $tenant =
            $this->tenant();

        $order =
            $this->order(
                $tenant
            );

        $payment =
            $this->inTenant(
                $tenant,
                fn (): Payment => app(
                    PaymentService::class
                )->forOrder(
                    $order
                ),
            );

        $service =
            app(
                PaymentService::class
            );

        $this->inTenant(
            $tenant,
            function () use (
                $service,
                $payment,
            ): void {
                $service->createAttempt(
                    $payment,
                    'attempt-a',
                    'gateway_card',
                    'mada',
                );

                $service->createAttempt(
                    $payment,
                    'attempt-b',
                    'gateway_card',
                    'visa',
                );

                $this->assertSame(
                    2,
                    PaymentAttempt::query()
                        ->count(),
                );
            },
        );
    }

    public function test_invalid_payment_codes_and_idempotency_keys_fail_before_persistence(): void
    {
        $tenant =
            $this->tenant();

        $order =
            $this->order(
                $tenant
            );

        $payment =
            $this->inTenant(
                $tenant,
                fn (): Payment => app(
                    PaymentService::class
                )->forOrder(
                    $order
                ),
            );

        try {
            $this->inTenant(
                $tenant,
                fn () => app(
                    PaymentService::class
                )->createAttempt(
                    $payment,
                    ' ',
                    'gateway_card',
                    'mada',
                ),
            );

            $this->fail(
                'Expected invalid idempotency key.'
            );
        } catch (
            InvalidArgumentException
        ) {
            $this->addToAssertionCount(
                1
            );
        }

        try {
            $this->inTenant(
                $tenant,
                fn () => app(
                    PaymentService::class
                )->createAttempt(
                    $payment,
                    'valid-key',
                    'gateway card',
                    'mada',
                ),
            );

            $this->fail(
                'Expected invalid provider code.'
            );
        } catch (
            InvalidArgumentException
        ) {
            $this->addToAssertionCount(
                1
            );
        }

        $this->inTenant(
            $tenant,
            fn () => $this->assertSame(
                0,
                PaymentAttempt::query()
                    ->count(),
            ),
        );
    }

    public function test_attempt_creation_rejects_foreign_tenant_payment(): void
    {
        $tenantA =
            $this->tenant(
                'Tenant A'
            );

        $tenantB =
            $this->tenant(
                'Tenant B'
            );

        $orderB =
            $this->order(
                $tenantB
            );

        $paymentB =
            $this->inTenant(
                $tenantB,
                fn (): Payment => app(
                    PaymentService::class
                )->forOrder(
                    $orderB
                ),
            );

        $this->expectException(
            LogicException::class
        );

        $this->inTenant(
            $tenantA,
            fn () => app(
                PaymentService::class
            )->createAttempt(
                $paymentB,
                'foreign-payment',
                'gateway_card',
                'mada',
            ),
        );
    }

    public function test_terminal_payment_replays_existing_attempt_but_rejects_new_attempt(): void
    {
        $tenant =
            $this->tenant();

        $order =
            $this->order(
                $tenant
            );

        $service =
            app(
                PaymentService::class
            );

        $payment =
            $this->inTenant(
                $tenant,
                fn (): Payment => $service->forOrder(
                    $order
                ),
            );

        $attempt =
            $this->inTenant(
                $tenant,
                fn (): PaymentAttempt => $service->createAttempt(
                    $payment,
                    'terminal-replay-key',
                    'gateway_card',
                    'mada',
                ),
            );

        $this->inTenant(
            $tenant,
            function () use ($payment): void {
                $payment->status =
                    PaymentStatus::PAID;

                $payment->paid_at =
                    now();

                $payment->save();
            },
        );

        $replay =
            $this->inTenant(
                $tenant,
                fn (): PaymentAttempt => $service->createAttempt(
                    $payment,
                    'terminal-replay-key',
                    'gateway_card',
                    'mada',
                ),
            );

        $this->assertSame(
            $attempt->id,
            $replay->id,
        );

        try {
            $this->inTenant(
                $tenant,
                fn () => $service->createAttempt(
                    $payment,
                    'terminal-new-key',
                    'gateway_card',
                    'visa',
                ),
            );

            $this->fail(
                'Expected terminal payment to reject a new attempt.'
            );
        } catch (LogicException) {
            $this->addToAssertionCount(
                1
            );
        }

        $this->inTenant(
            $tenant,
            fn () => $this->assertSame(
                1,
                PaymentAttempt::query()
                    ->count(),
            ),
        );
    }

    public function test_terminal_order_replays_existing_payment_but_cannot_materialize_new_payment(): void
    {
        $tenant =
            $this->tenant();

        $service =
            app(
                PaymentService::class
            );

        $existingOrder =
            $this->order(
                $tenant
            );

        $existingPayment =
            $this->inTenant(
                $tenant,
                fn (): Payment => $service->forOrder(
                    $existingOrder
                ),
            );

        $this->inTenant(
            $tenant,
            function () use ($existingOrder): void {
                $existingOrder->status =
                    OrderStatus::CONFIRMED;

                $existingOrder->confirmed_at =
                    now();

                $existingOrder->save();
            },
        );

        $replay =
            $this->inTenant(
                $tenant,
                fn (): Payment => $service->forOrder(
                    $existingOrder
                ),
            );

        $this->assertSame(
            $existingPayment->id,
            $replay->id,
        );

        $cancelledOrder =
            $this->order(
                $tenant
            );

        $this->inTenant(
            $tenant,
            function () use ($cancelledOrder): void {
                $cancelledOrder->status =
                    OrderStatus::CANCELLED;

                $cancelledOrder->cancelled_at =
                    now();

                $cancelledOrder->save();
            },
        );

        try {
            $this->inTenant(
                $tenant,
                fn () => $service->forOrder(
                    $cancelledOrder
                ),
            );

            $this->fail(
                'Expected terminal order to reject new payment materialization.'
            );
        } catch (LogicException) {
            $this->addToAssertionCount(
                1
            );
        }

        $this->inTenant(
            $tenant,
            fn () => $this->assertSame(
                1,
                Payment::query()
                    ->count(),
            ),
        );
    }
}
