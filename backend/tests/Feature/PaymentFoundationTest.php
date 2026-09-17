<?php

namespace Tests\Feature;

use App\Models\AppInstance;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Tenant;
use App\Services\Cart\CartService;
use App\Support\Order\OrderStatus;
use App\Support\Payment\PaymentAttemptStatus;
use App\Support\Payment\PaymentStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class PaymentFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(
        string $name,
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

    private function appInstance(
        Tenant $tenant,
    ): AppInstance {
        return AppInstance::query()->create([
            'tenant_id' => $tenant->id,
            'channel' => 'mobile',
            'is_active' => true,
        ]);
    }

    private function order(
        Tenant $tenant,
    ): Order {
        $instance =
            $this->appInstance(
                $tenant
            );

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

        return $this->inTenant(
            $tenant,
            fn (): Order => Order::query()->create([
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
            ]),
        );
    }

    private function payment(
        Tenant $tenant,
        Order $order,
    ): Payment {
        return $this->inTenant(
            $tenant,
            fn (): Payment => Payment::query()->create([
                'order_id' => $order->id,

                'public_id' => (string) Str::uuid(),

                'status' => PaymentStatus::PENDING,

                'currency_code' => $order->currency_code,

                'amount_minor' => $order->total_minor,
            ]),
        );
    }

    public function test_payments_fail_closed_without_tenant_context(): void
    {
        $tenant =
            $this->tenant(
                'Tenant A'
            );

        $order =
            $this->order(
                $tenant
            );

        $this->expectException(
            RuntimeException::class
        );

        Payment::query()->create([
            'order_id' => $order->id,

            'public_id' => (string) Str::uuid(),

            'status' => PaymentStatus::PENDING,

            'currency_code' => 'SAR',

            'amount_minor' => 1150,
        ]);
    }

    public function test_payment_and_attempt_snapshot_identity_and_money(): void
    {
        $tenant =
            $this->tenant(
                'Tenant A'
            );

        $order =
            $this->order(
                $tenant
            );

        $payment =
            $this->payment(
                $tenant,
                $order,
            );

        $attempt =
            $this->inTenant(
                $tenant,
                fn (): PaymentAttempt => PaymentAttempt::query()
                    ->create([
                        'payment_id' => $payment->id,

                        'public_id' => (string) Str::uuid(),

                        'idempotency_key' => 'attempt-001',

                        'provider_code' => 'gateway_card',

                        'method_code' => 'mada',

                        'provider_reference' => 'provider-001',

                        'status' => PaymentAttemptStatus::CREATED,

                        'currency_code' => 'SAR',

                        'amount_minor' => 1150,
                    ]),
            );

        $this->assertSame(
            PaymentStatus::PENDING,
            $payment->status,
        );

        $this->assertSame(
            1150,
            $payment->amount_minor,
        );

        $this->assertSame(
            'SAR',
            $payment->currency_code,
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
            'mada',
            $attempt->method_code,
        );

        $this->assertSame(
            'provider-001',
            $attempt->provider_reference,
        );

        $this->assertSame(
            1150,
            $attempt->amount_minor,
        );
    }

    public function test_order_can_have_only_one_payment_aggregate(): void
    {
        $tenant =
            $this->tenant(
                'Tenant A'
            );

        $order =
            $this->order(
                $tenant
            );

        $this->payment(
            $tenant,
            $order,
        );

        $this->expectException(
            QueryException::class
        );

        $this->payment(
            $tenant,
            $order,
        );
    }

    public function test_payment_cannot_reference_foreign_tenant_order(): void
    {
        $tenantA =
            $this->tenant(
                'Tenant A'
            );

        $tenantB =
            $this->tenant(
                'Tenant B'
            );

        $foreignOrder =
            $this->order(
                $tenantB
            );

        $this->expectException(
            QueryException::class
        );

        $this->payment(
            $tenantA,
            $foreignOrder,
        );
    }

    public function test_payment_attempt_cannot_reference_foreign_tenant_payment(): void
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
            $this->payment(
                $tenantB,
                $orderB,
            );

        $this->expectException(
            QueryException::class
        );

        $this->inTenant(
            $tenantA,
            fn () => PaymentAttempt::query()
                ->create([
                    'payment_id' => $paymentB->id,

                    'public_id' => (string) Str::uuid(),

                    'idempotency_key' => 'foreign-attempt',

                    'provider_code' => 'gateway_card',

                    'method_code' => 'visa',

                    'status' => PaymentAttemptStatus::CREATED,

                    'currency_code' => 'SAR',

                    'amount_minor' => 1150,
                ]),
        );
    }

    public function test_attempt_idempotency_key_is_unique_inside_tenant(): void
    {
        $tenant =
            $this->tenant(
                'Tenant A'
            );

        $order =
            $this->order(
                $tenant
            );

        $payment =
            $this->payment(
                $tenant,
                $order,
            );

        $create =
            function () use (
                $tenant,
                $payment,
            ): PaymentAttempt {
                return $this->inTenant(
                    $tenant,
                    fn (): PaymentAttempt => PaymentAttempt::query()
                        ->create([
                            'payment_id' => $payment->id,

                            'public_id' => (string) Str::uuid(),

                            'idempotency_key' => 'same-key',

                            'provider_code' => 'gateway_card',

                            'method_code' => 'apple_pay',

                            'status' => PaymentAttemptStatus::CREATED,

                            'currency_code' => 'SAR',

                            'amount_minor' => 1150,
                        ]),
                );
            };

        $create();

        $this->expectException(
            QueryException::class
        );

        $create();
    }

    public function test_provider_reference_is_unique_per_tenant_and_provider(): void
    {
        $tenant =
            $this->tenant(
                'Tenant A'
            );

        $order =
            $this->order(
                $tenant
            );

        $payment =
            $this->payment(
                $tenant,
                $order,
            );

        $this->inTenant(
            $tenant,
            fn () => PaymentAttempt::query()
                ->create([
                    'payment_id' => $payment->id,

                    'public_id' => (string) Str::uuid(),

                    'idempotency_key' => 'provider-key-1',

                    'provider_code' => 'gateway_card',

                    'method_code' => 'mada',

                    'provider_reference' => 'provider-ref',

                    'status' => PaymentAttemptStatus::CREATED,

                    'currency_code' => 'SAR',

                    'amount_minor' => 1150,
                ]),
        );

        $this->expectException(
            QueryException::class
        );

        $this->inTenant(
            $tenant,
            fn () => PaymentAttempt::query()
                ->create([
                    'payment_id' => $payment->id,

                    'public_id' => (string) Str::uuid(),

                    'idempotency_key' => 'provider-key-2',

                    'provider_code' => 'gateway_card',

                    'method_code' => 'visa',

                    'provider_reference' => 'provider-ref',

                    'status' => PaymentAttemptStatus::CREATED,

                    'currency_code' => 'SAR',

                    'amount_minor' => 1150,
                ]),
        );
    }

    public function test_postgres_rejects_invalid_payment_status(): void
    {
        if (
            DB::connection()->getDriverName()
            !== 'pgsql'
        ) {
            $this->markTestSkipped(
                'PostgreSQL-specific Payment constraint proof.'
            );
        }

        $tenant =
            $this->tenant(
                'Tenant A'
            );

        $order =
            $this->order(
                $tenant
            );

        $this->expectException(
            QueryException::class
        );

        $this->inTenant(
            $tenant,
            fn () => Payment::query()->create([
                'order_id' => $order->id,

                'public_id' => (string) Str::uuid(),

                'status' => 'forged-status',

                'currency_code' => 'SAR',

                'amount_minor' => 1150,
            ]),
        );
    }
}
