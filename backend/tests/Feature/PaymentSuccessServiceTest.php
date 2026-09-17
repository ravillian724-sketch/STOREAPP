<?php

namespace Tests\Feature;

use App\Models\AppInstance;
use App\Models\InventoryLocation;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentWebhookReceipt;
use App\Models\Product;
use App\Models\Sku;
use App\Models\Tenant;
use App\Services\Cart\CartService;
use App\Services\Inventory\InventoryAvailabilityService;
use App\Services\Inventory\InventoryReservationService;
use App\Services\Inventory\StockLedgerService;
use App\Services\Payment\PaymentProviderReferenceService;
use App\Services\Payment\PaymentService;
use App\Services\Payment\PaymentSuccessService;
use App\Support\Inventory\InventoryMovementType;
use App\Support\Inventory\InventoryReservationStatus;
use App\Support\Order\OrderStatus;
use App\Support\Payment\PaymentAttemptStatus;
use App\Support\Payment\PaymentStatus;
use App\Support\Payment\PaymentWebhookProcessingOutcome;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class PaymentSuccessServiceTest extends TestCase
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
     * item: OrderItem,
     * reservation: InventoryReservation,
     * payment: Payment,
     * attempt: PaymentAttempt,
     * receipt: PaymentWebhookReceipt,
     * processed_at: CarbonImmutable
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

                $location =
                    InventoryLocation::query()
                        ->create([
                            'branch_id' => null,
                            'code' => 'MAIN',
                            'name_ar' => 'المخزن الرئيسي',
                            'name_en' => 'Main Stock',
                            'type' => 'stock',
                            'is_active' => true,
                        ]);

                $product =
                    Product::query()
                        ->create([
                            'name_ar' => 'منتج',
                            'name_en' => 'Product',
                            'is_active' => true,
                        ]);

                $sku =
                    Sku::query()
                        ->create([
                            'product_id' => $product->id,

                            'code' => 'SKU-PAYMENT',

                            'barcode' => 'PAYMENT-BARCODE',

                            'name_ar' => 'عبوة',

                            'name_en' => 'Pack',

                            'track_inventory' => true,

                            'is_active' => true,
                        ]);

                app(
                    StockLedgerService::class
                )->post(
                    $sku,
                    $location,
                    10,
                    InventoryMovementType::OPENING,
                    'opening-payment-success',
                );

                $order =
                    Order::query()
                        ->create([
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

                $item =
                    OrderItem::query()
                        ->create([
                            'order_id' => $order->id,

                            'sku_id' => $sku->id,

                            'location_id' => $location->id,

                            'public_id' => (string) Str::uuid(),

                            'sku_code_snapshot' => $sku->code,

                            'barcode_snapshot' => $sku->barcode,

                            'product_name_ar_snapshot' => $product->name_ar,

                            'product_name_en_snapshot' => $product->name_en,

                            'sku_name_ar_snapshot' => $sku->name_ar,

                            'sku_name_en_snapshot' => $sku->name_en,

                            'quantity' => 2,

                            'unit_net_minor' => 500,

                            'line_subtotal_minor' => 1000,

                            'discount_minor' => 0,

                            'tax_rate_bps' => 1500,

                            'tax_minor' => 150,

                            'line_total_minor' => 1150,
                        ]);

                $reservation =
                    app(
                        InventoryReservationService::class
                    )->reserve(
                        $sku,
                        $location,
                        2,
                        'payment-success-reservation',
                        'order_item',
                        $item->public_id,
                        $at->addMinutes(15),
                    );

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
                        'payment-success-attempt',
                        'gateway_card',
                        'mada',
                    );

                $attempt =
                    app(
                        PaymentProviderReferenceService::class
                    )->bind(
                        $attempt,
                        'provider-payment-001',
                    );

                $receipt =
                    PaymentWebhookReceipt::query()
                        ->create([
                            'payment_attempt_id' => $attempt->id,

                            'public_id' => (string) Str::uuid(),

                            'provider_code' => 'gateway_card',

                            'provider_event_id' => 'provider-event-success-001',

                            'provider_reference' => 'provider-payment-001',

                            'event_type' => 'payment.succeeded',

                            'amount_minor' => 1150,

                            'currency_code' => 'SAR',

                            'payload_sha256' => hash(
                                'sha256',
                                'success-payload'
                            ),

                            'occurred_at' => $at->addSecond(),

                            'received_at' => $at->addSeconds(2),
                        ]);

                return [
                    'tenant' => $tenant,

                    'order' => $order,

                    'item' => $item,

                    'reservation' => $reservation,

                    'payment' => $payment,

                    'attempt' => $attempt,

                    'receipt' => $receipt,

                    'processed_at' => $at->addSeconds(3),
                ];
            },
        );
    }

    public function test_success_atomically_pays_confirms_and_commits_inventory(): void
    {
        $fixture =
            $this->fixture();

        $this->inTenant(
            $fixture['tenant'],
            function () use (
                $fixture
            ): void {
                $payment =
                    app(
                        PaymentSuccessService::class
                    )->confirm(
                        $fixture['receipt'],
                        $fixture['processed_at'],
                    );

                $order =
                    $fixture['order']
                        ->refresh();

                $attempt =
                    $fixture['attempt']
                        ->refresh();

                $reservation =
                    $fixture['reservation']
                        ->refresh();

                $receipt =
                    $fixture['receipt']
                        ->refresh();

                $this->assertSame(
                    PaymentStatus::PAID,
                    $payment->status,
                );

                $this->assertSame(
                    PaymentAttemptStatus::SUCCEEDED,
                    $attempt->status,
                );

                $this->assertSame(
                    OrderStatus::CONFIRMED,
                    $order->status,
                );

                $this->assertSame(
                    InventoryReservationStatus::ACTIVE,
                    $reservation->status,
                );

                $this->assertNull(
                    $reservation->expires_at
                );

                $this->assertNotNull(
                    $payment->paid_at
                );

                $this->assertNotNull(
                    $attempt->succeeded_at
                );

                $this->assertNotNull(
                    $order->confirmed_at
                );

                $this->assertNotNull(
                    $receipt->processed_at
                );

                $this->assertSame(
                    PaymentWebhookProcessingOutcome::APPLIED,
                    $receipt->processing_outcome,
                );
            },
        );
    }

    public function test_success_replay_preserves_original_lifecycle_timestamps(): void
    {
        $fixture =
            $this->fixture();

        $this->inTenant(
            $fixture['tenant'],
            function () use (
                $fixture
            ): void {
                $service =
                    app(
                        PaymentSuccessService::class
                    );

                $first =
                    $service->confirm(
                        $fixture['receipt'],
                        $fixture['processed_at'],
                    );

                $firstPaidAt =
                    $first->paid_at;

                $firstConfirmedAt =
                    $fixture['order']
                        ->refresh()
                        ->confirmed_at;

                $second =
                    $service->confirm(
                        $fixture['receipt']->refresh(),
                        $fixture['processed_at']
                            ->addMinute(),
                    );

                $this->assertSame(
                    $first->id,
                    $second->id,
                );

                $this->assertTrue(
                    $second->paid_at
                        ->equalTo(
                            $firstPaidAt
                        )
                );

                $this->assertTrue(
                    $fixture['order']
                        ->refresh()
                        ->confirmed_at
                        ->equalTo(
                            $firstConfirmedAt
                        )
                );

                $this->assertNull(
                    $fixture['reservation']
                        ->refresh()
                        ->expires_at
                );
            },
        );
    }

    public function test_expired_inventory_hold_rejects_payment_success_atomically(): void
    {
        $fixture =
            $this->fixture();

        $this->inTenant(
            $fixture['tenant'],
            function () use (
                $fixture
            ): void {
                $reservation =
                    $fixture['reservation'];

                $reservation->expires_at =
                    $fixture['processed_at']
                        ->subSecond();

                $reservation->save();

                try {
                    app(
                        PaymentSuccessService::class
                    )->confirm(
                        $fixture['receipt'],
                        $fixture['processed_at'],
                    );

                    $this->fail(
                        'Expected expired reservation rejection.'
                    );
                } catch (LogicException) {
                    $this->addToAssertionCount(
                        1
                    );
                }

                $this->assertSame(
                    PaymentStatus::PENDING,
                    $fixture['payment']
                        ->refresh()
                        ->status,
                );

                $this->assertSame(
                    PaymentAttemptStatus::CREATED,
                    $fixture['attempt']
                        ->refresh()
                        ->status,
                );

                $this->assertSame(
                    OrderStatus::PENDING,
                    $fixture['order']
                        ->refresh()
                        ->status,
                );

                $this->assertNull(
                    $fixture['receipt']
                        ->refresh()
                        ->processed_at
                );

                $this->assertNotNull(
                    $fixture['reservation']
                        ->refresh()
                        ->expires_at
                );
            },
        );
    }

    public function test_wrong_money_evidence_is_rejected_without_state_change(): void
    {
        $fixture =
            $this->fixture();

        $this->inTenant(
            $fixture['tenant'],
            function () use (
                $fixture
            ): void {
                $receipt =
                    $fixture['receipt'];

                $receipt->amount_minor =
                    1149;

                $receipt->save();

                $this->expectException(
                    LogicException::class
                );

                try {
                    app(
                        PaymentSuccessService::class
                    )->confirm(
                        $receipt,
                        $fixture['processed_at'],
                    );
                } finally {
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
                }
            },
        );
    }

    public function test_wrong_event_type_cannot_trigger_success_policy(): void
    {
        $fixture =
            $this->fixture();

        $fixture['receipt']->event_type =
            'payment.failed';

        $fixture['receipt']->save();

        $this->expectException(
            LogicException::class
        );

        $this->inTenant(
            $fixture['tenant'],
            fn () => app(
                PaymentSuccessService::class
            )->confirm(
                $fixture['receipt'],
                $fixture['processed_at'],
            ),
        );
    }

    public function test_foreign_tenant_cannot_process_payment_success(): void
    {
        $fixture =
            $this->fixture();

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
                PaymentSuccessService::class
            )->confirm(
                $fixture['receipt'],
                $fixture['processed_at'],
            ),
        );
    }

    public function test_success_preserves_on_hand_and_ats(): void
    {
        $fixture =
            $this->fixture();

        $this->inTenant(
            $fixture['tenant'],
            function () use ($fixture): void {
                $reservation =
                    $fixture['reservation']
                        ->refresh();

                $sku =
                    $reservation->sku()
                        ->firstOrFail();

                $location =
                    $reservation->location()
                        ->firstOrFail();

                $availability =
                    app(
                        InventoryAvailabilityService::class
                    );

                $beforeOnHand =
                    $availability->onHand(
                        $sku,
                        $location,
                    );

                $beforeAts =
                    $availability->availableToSell(
                        $sku,
                        $location,
                    );

                $this->assertSame(
                    10,
                    $beforeOnHand,
                );

                $this->assertSame(
                    8,
                    $beforeAts,
                );

                app(
                    PaymentSuccessService::class
                )->confirm(
                    $fixture['receipt'],
                    $fixture['processed_at'],
                );

                $this->assertSame(
                    $beforeOnHand,
                    $availability->onHand(
                        $sku,
                        $location,
                    ),
                );

                $this->assertSame(
                    $beforeAts,
                    $availability->availableToSell(
                        $sku,
                        $location,
                    ),
                );

                $reservation->refresh();

                $this->assertSame(
                    InventoryReservationStatus::ACTIVE,
                    $reservation->status,
                );

                $this->assertNull(
                    $reservation->expires_at
                );
            },
        );
    }

    public function test_preprocessed_receipt_cannot_settle_unsettled_aggregate(): void
    {
        $fixture =
            $this->fixture();

        $this->inTenant(
            $fixture['tenant'],
            function () use ($fixture): void {
                $receipt =
                    $fixture['receipt'];

                $receipt->processed_at =
                    $fixture['processed_at'];

                $receipt->save();

                try {
                    app(
                        PaymentSuccessService::class
                    )->confirm(
                        $receipt,
                        $fixture['processed_at']
                            ->addSecond(),
                    );

                    $this->fail(
                        'Expected incoherent processed receipt rejection.'
                    );
                } catch (LogicException) {
                    $this->addToAssertionCount(
                        1
                    );
                }

                $this->assertSame(
                    PaymentStatus::PENDING,
                    $fixture['payment']
                        ->refresh()
                        ->status,
                );

                $this->assertSame(
                    PaymentAttemptStatus::CREATED,
                    $fixture['attempt']
                        ->refresh()
                        ->status,
                );

                $this->assertSame(
                    OrderStatus::PENDING,
                    $fixture['order']
                        ->refresh()
                        ->status,
                );

                $this->assertNotNull(
                    $fixture['reservation']
                        ->refresh()
                        ->expires_at
                );
            },
        );
    }

    public function test_provider_identity_mismatch_is_rejected_without_settlement(): void
    {
        $fixture =
            $this->fixture();

        $this->inTenant(
            $fixture['tenant'],
            function () use ($fixture): void {
                $receipt =
                    $fixture['receipt'];

                $receipt->provider_reference =
                    'different-provider-reference';

                $receipt->save();

                try {
                    app(
                        PaymentSuccessService::class
                    )->confirm(
                        $receipt,
                        $fixture['processed_at'],
                    );

                    $this->fail(
                        'Expected provider identity mismatch.'
                    );
                } catch (LogicException) {
                    $this->addToAssertionCount(
                        1
                    );
                }

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

    public function test_success_after_terminal_failure_requires_reconciliation_without_downgrade(): void
    {
        $fixture =
            $this->fixture();

        $this->inTenant(
            $fixture['tenant'],
            function () use (
                $fixture
            ): void {
                $attempt =
                    $fixture['attempt'];

                $attempt->status =
                    PaymentAttemptStatus::FAILED;

                $attempt->failed_at =
                    $fixture['processed_at']
                        ->subSeconds(2);

                $attempt->save();

                $reservation =
                    $fixture['reservation']
                        ->refresh();

                $expiresAtBefore =
                    $reservation->expires_at;

                $payment =
                    app(
                        PaymentSuccessService::class
                    )->confirm(
                        $fixture['receipt'],
                        $fixture['processed_at'],
                    );

                $this->assertSame(
                    PaymentStatus::PENDING,
                    $payment->status,
                );

                $this->assertSame(
                    PaymentAttemptStatus::FAILED,
                    $attempt->refresh()
                        ->status,
                );

                $this->assertSame(
                    OrderStatus::PENDING,
                    $fixture['order']
                        ->refresh()
                        ->status,
                );

                $reservation->refresh();

                $this->assertSame(
                    InventoryReservationStatus::ACTIVE,
                    $reservation->status,
                );

                $this->assertTrue(
                    $reservation->expires_at
                        ->equalTo(
                            $expiresAtBefore
                        )
                );

                $receipt =
                    $fixture['receipt']
                        ->refresh();

                $this->assertNotNull(
                    $receipt->processed_at
                );

                $this->assertSame(
                    PaymentWebhookProcessingOutcome::REQUIRES_RECONCILIATION,
                    $receipt->processing_outcome,
                );
            },
        );
    }
}
