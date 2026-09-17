<?php

namespace Tests\Feature;

use App\Contracts\Payment\PaymentProviderWebhookVerifier;
use App\Exceptions\Payment\PaymentWebhookReplayConflictException;
use App\Models\AppInstance;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentWebhookReceipt;
use App\Models\Tenant;
use App\Services\Cart\CartService;
use App\Services\Payment\PaymentProviderReferenceService;
use App\Services\Payment\PaymentService;
use App\Services\Payment\PaymentWebhookIngressService;
use App\Support\Order\OrderStatus;
use App\Support\Payment\PaymentAttemptStatus;
use App\Support\Payment\PaymentStatus;
use App\Support\Payment\VerifiedPaymentWebhook;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class PaymentWebhookBoundaryTest extends TestCase
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

    private function paymentAttempt(
        Tenant $tenant,
    ): array {
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
                    'attempt-001',
                    'gateway_card',
                    'mada',
                ),
            );

        return [
            $payment,
            $attempt,
        ];
    }

    private function event(
        string $providerReference =
            'provider-ref-001',
        string $providerEventId =
            'event-001',
        string $eventType =
            'payment.succeeded',
    ): VerifiedPaymentWebhook {
        return new VerifiedPaymentWebhook(
            providerCode: 'gateway_card',

            providerEventId: $providerEventId,

            providerReference: $providerReference,

            eventType: $eventType,

            occurredAt: CarbonImmutable::parse(
                '2026-09-17T16:00:00+00:00'
            ),
        );
    }

    public function test_provider_reference_binding_is_idempotent_and_immutable(): void
    {
        $tenant =
            $this->tenant();

        [, $attempt] =
            $this->paymentAttempt(
                $tenant
            );

        $service =
            app(
                PaymentProviderReferenceService::class
            );

        $first =
            $this->inTenant(
                $tenant,
                fn (): PaymentAttempt => $service->bind(
                    $attempt,
                    'provider-ref-001',
                ),
            );

        $replay =
            $this->inTenant(
                $tenant,
                fn (): PaymentAttempt => $service->bind(
                    $attempt,
                    'provider-ref-001',
                ),
            );

        $this->assertSame(
            $first->id,
            $replay->id,
        );

        $this->assertSame(
            'provider-ref-001',
            $replay->provider_reference,
        );

        $this->expectException(
            LogicException::class
        );

        $this->inTenant(
            $tenant,
            fn () => $service->bind(
                $attempt,
                'provider-ref-002',
            ),
        );
    }

    public function test_verified_webhook_is_correlated_without_mutating_payment_lifecycle(): void
    {
        $tenant =
            $this->tenant();

        [$payment, $attempt] =
            $this->paymentAttempt(
                $tenant
            );

        $this->inTenant(
            $tenant,
            fn () => app(
                PaymentProviderReferenceService::class
            )->bind(
                $attempt,
                'provider-ref-001',
            ),
        );

        $rawBody =
            '{"id":"event-001","status":"paid"}';

        $receipt =
            $this->inTenant(
                $tenant,
                fn (): PaymentWebhookReceipt => app(
                    PaymentWebhookIngressService::class
                )->ingest(
                    new FakePaymentWebhookVerifier(
                        'gateway_card',
                        $this->event(),
                    ),
                    $rawBody,
                    [
                        'x-signature' => 'verified-test-signature',
                    ],
                    CarbonImmutable::parse(
                        '2026-09-17T16:00:05+00:00'
                    ),
                ),
            );

        $this->assertSame(
            $attempt->id,
            $receipt->payment_attempt_id,
        );

        $this->assertSame(
            hash(
                'sha256',
                $rawBody,
            ),
            $receipt->payload_sha256,
        );

        $this->assertArrayNotHasKey(
            'raw_body',
            $receipt->getAttributes(),
        );

        $this->assertArrayNotHasKey(
            'headers',
            $receipt->getAttributes(),
        );

        $freshPayment =
            $this->inTenant(
                $tenant,
                fn (): Payment => Payment::query()
                    ->findOrFail(
                        $payment->id
                    ),
            );

        $freshAttempt =
            $this->inTenant(
                $tenant,
                fn (): PaymentAttempt => PaymentAttempt::query()
                    ->findOrFail(
                        $attempt->id
                    ),
            );

        $this->assertSame(
            PaymentStatus::PENDING,
            $freshPayment->status,
        );

        $this->assertSame(
            PaymentAttemptStatus::CREATED,
            $freshAttempt->status,
        );
    }

    public function test_unverified_webhook_is_rejected_before_persistence(): void
    {
        $tenant =
            $this->tenant();

        $verifier =
            new FakePaymentWebhookVerifier(
                'gateway_card',
                $this->event(),
                reject: true,
            );

        try {
            $this->inTenant(
                $tenant,
                fn () => app(
                    PaymentWebhookIngressService::class
                )->ingest(
                    $verifier,
                    '{"id":"event-001"}',
                    [],
                    CarbonImmutable::parse(
                        '2026-09-17T16:00:05+00:00'
                    ),
                ),
            );

            $this->fail(
                'Expected verification failure.'
            );
        } catch (LogicException) {
            $this->addToAssertionCount(
                1
            );
        }

        $this->inTenant(
            $tenant,
            fn () => $this->assertSame(
                0,
                PaymentWebhookReceipt::query()
                    ->count(),
            ),
        );
    }

    public function test_same_provider_event_replays_same_receipt(): void
    {
        $tenant =
            $this->tenant();

        $rawBody =
            '{"id":"event-001"}';

        $verifier =
            new FakePaymentWebhookVerifier(
                'gateway_card',
                $this->event(),
            );

        $first =
            $this->inTenant(
                $tenant,
                fn (): PaymentWebhookReceipt => app(
                    PaymentWebhookIngressService::class
                )->ingest(
                    $verifier,
                    $rawBody,
                    [],
                    CarbonImmutable::parse(
                        '2026-09-17T16:00:05+00:00'
                    ),
                ),
            );

        $replay =
            $this->inTenant(
                $tenant,
                fn (): PaymentWebhookReceipt => app(
                    PaymentWebhookIngressService::class
                )->ingest(
                    $verifier,
                    $rawBody,
                    [],
                    CarbonImmutable::parse(
                        '2026-09-17T16:10:00+00:00'
                    ),
                ),
            );

        $this->assertSame(
            $first->id,
            $replay->id,
        );

        $this->assertSame(
            1,
            $this->inTenant(
                $tenant,
                fn (): int => PaymentWebhookReceipt::query()
                    ->count(),
            ),
        );
    }

    public function test_replayed_event_with_changed_payload_is_rejected(): void
    {
        $tenant =
            $this->tenant();

        $verifier =
            new FakePaymentWebhookVerifier(
                'gateway_card',
                $this->event(),
            );

        $this->inTenant(
            $tenant,
            fn () => app(
                PaymentWebhookIngressService::class
            )->ingest(
                $verifier,
                '{"value":1}',
                [],
                CarbonImmutable::parse(
                    '2026-09-17T16:00:05+00:00'
                ),
            ),
        );

        $this->expectException(
            PaymentWebhookReplayConflictException::class
        );

        $this->inTenant(
            $tenant,
            fn () => app(
                PaymentWebhookIngressService::class
            )->ingest(
                $verifier,
                '{"value":2}',
                [],
                CarbonImmutable::parse(
                    '2026-09-17T16:00:06+00:00'
                ),
            ),
        );
    }

    public function test_webhook_can_arrive_before_reference_binding_and_later_replay_correlates_attempt(): void
    {
        $tenant =
            $this->tenant();

        [, $attempt] =
            $this->paymentAttempt(
                $tenant
            );

        $rawBody =
            '{"id":"event-late"}';

        $verifier =
            new FakePaymentWebhookVerifier(
                'gateway_card',
                $this->event(
                    providerReference: 'provider-late',

                    providerEventId: 'event-late',
                ),
            );

        $first =
            $this->inTenant(
                $tenant,
                fn (): PaymentWebhookReceipt => app(
                    PaymentWebhookIngressService::class
                )->ingest(
                    $verifier,
                    $rawBody,
                    [],
                    CarbonImmutable::parse(
                        '2026-09-17T16:00:05+00:00'
                    ),
                ),
            );

        $this->assertNull(
            $first->payment_attempt_id
        );

        $this->inTenant(
            $tenant,
            fn () => app(
                PaymentProviderReferenceService::class
            )->bind(
                $attempt,
                'provider-late',
            ),
        );

        $replay =
            $this->inTenant(
                $tenant,
                fn (): PaymentWebhookReceipt => app(
                    PaymentWebhookIngressService::class
                )->ingest(
                    $verifier,
                    $rawBody,
                    [],
                    CarbonImmutable::parse(
                        '2026-09-17T16:01:00+00:00'
                    ),
                ),
            );

        $this->assertSame(
            $attempt->id,
            $replay->payment_attempt_id,
        );
    }

    public function test_verifier_provider_mismatch_is_rejected(): void
    {
        $tenant =
            $this->tenant();

        $verifier =
            new FakePaymentWebhookVerifier(
                'tamara',
                $this->event(),
            );

        $this->expectException(
            LogicException::class
        );

        $this->inTenant(
            $tenant,
            fn () => app(
                PaymentWebhookIngressService::class
            )->ingest(
                $verifier,
                '{"id":"event-001"}',
                [],
                CarbonImmutable::parse(
                    '2026-09-17T16:00:05+00:00'
                ),
            ),
        );
    }

    public function test_foreign_tenant_cannot_bind_provider_reference(): void
    {
        $tenantA =
            $this->tenant(
                'Tenant A'
            );

        $tenantB =
            $this->tenant(
                'Tenant B'
            );

        [, $attemptB] =
            $this->paymentAttempt(
                $tenantB
            );

        $this->expectException(
            LogicException::class
        );

        $this->inTenant(
            $tenantA,
            fn () => app(
                PaymentProviderReferenceService::class
            )->bind(
                $attemptB,
                'foreign-ref',
            ),
        );
    }

    public function test_postgres_webhook_receipts_have_forced_rls(): void
    {
        if (
            DB::connection()->getDriverName()
            !== 'pgsql'
        ) {
            $this->markTestSkipped(
                'PostgreSQL-specific webhook RLS proof.'
            );
        }

        $row =
            DB::selectOne(
                <<<'SQL'
                SELECT
                    relrowsecurity,
                    relforcerowsecurity
                FROM pg_class
                WHERE relname =
                    'payment_webhook_receipts'
                SQL
            );

        $this->assertNotNull(
            $row
        );

        $this->assertTrue(
            (bool) $row->relrowsecurity
        );

        $this->assertTrue(
            (bool) $row->relforcerowsecurity
        );
    }
}

final class FakePaymentWebhookVerifier implements PaymentProviderWebhookVerifier
{
    public function __construct(
        private readonly string $provider,
        private readonly VerifiedPaymentWebhook $event,
        private readonly bool $reject = false,
    ) {}

    public function providerCode(): string
    {
        return $this->provider;
    }

    public function verify(
        string $rawBody,
        array $headers,
    ): VerifiedPaymentWebhook {
        if ($this->reject) {
            throw new LogicException(
                'Invalid provider webhook signature.'
            );
        }

        return $this->event;
    }
}
