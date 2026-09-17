<?php

namespace Tests\Feature;

use App\Models\AppInstance;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\InventoryLocation;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Sku;
use App\Models\Tenant;
use App\Services\Cart\CartCheckoutReservationService;
use App\Services\Cart\CartService;
use App\Services\Inventory\InventoryAvailabilityService;
use App\Services\Inventory\InventoryReservationService;
use App\Services\Inventory\StockLedgerService;
use App\Services\Order\OrderConversionService;
use App\Services\Pricing\SkuPriceService;
use App\Support\Cart\CartStatus;
use App\Support\Inventory\InventoryMovementType;
use App\Support\Inventory\InventoryReservationStatus;
use App\Support\Order\OrderCheckoutSnapshot;
use App\Support\Order\OrderStatus;
use App\Support\Pricing\ShippingQuote;
use App\Support\Pricing\TaxBreakdown;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class OrderConversionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Tenant
    {
        return Tenant::query()->create([
            'name_ar' => 'Tenant A',
            'name_en' => 'Tenant A',
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

    private function shipping(
        CarbonImmutable $at,
    ): ShippingQuote {
        return new ShippingQuote(
            currencyCode: 'SAR',

            breakdown: new TaxBreakdown(
                netMinor: 500,
                taxMinor: 75,
                grossMinor: 575,
                taxRateBps: 1500,
            ),

            validUntil: $at->addMinutes(10),
        );
    }

    private function checkoutSnapshot(): OrderCheckoutSnapshot
    {
        return new OrderCheckoutSnapshot(
            customerName: 'Guest Customer',

            customerPhone: '+966500000000',

            customerEmail: 'guest@example.com',

            shippingAddressSnapshot: [
                'city' => 'Jeddah',
                'country_code' => 'SA',
                'district' => 'Al Rawdah',
            ],
        );
    }

    /**
     * @return array{
     *   tenant: Tenant,
     *   instance: AppInstance,
     *   cart: Cart,
     *   location: InventoryLocation,
     *   products: list<Product>,
     *   skus: list<Sku>,
     *   items: list<CartItem>,
     *   at: CarbonImmutable,
     *   reservation_expires_at: CarbonImmutable
     * }
     */
    private function fixture(
        int $lineCount = 1,
    ): array {
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

        $reservationExpiresAt =
            $at->addMinutes(15);

        return $this->inTenant(
            $tenant,
            function () use (
                $tenant,
                $instance,
                $at,
                $reservationExpiresAt,
                $lineCount,
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

                $products = [];
                $skus = [];
                $items = [];

                for (
                    $i = 1;
                    $i <= $lineCount;
                    $i++
                ) {
                    $product =
                        Product::query()
                            ->create([
                                'name_ar' => 'منتج '.$i,

                                'name_en' => 'Product '.$i,

                                'is_active' => true,
                            ]);

                    $sku =
                        Sku::query()
                            ->create([
                                'product_id' => $product->id,

                                'code' => 'SKU-'.$i,

                                'barcode' => 'BAR-'.$i,

                                'name_ar' => 'عبوة '.$i,

                                'name_en' => 'Pack '.$i,

                                'track_inventory' => true,

                                'is_active' => true,
                            ]);

                    $item =
                        CartItem::query()
                            ->create([
                                'cart_id' => $cart->id,

                                'sku_id' => $sku->id,

                                'location_id' => $location->id,

                                'public_id' => (string)
                                    Str::uuid(),

                                'quantity' => 2,
                            ]);

                    app(
                        StockLedgerService::class
                    )->post(
                        $sku,
                        $location,
                        10,
                        InventoryMovementType::OPENING,
                        'opening-'.$sku->id,
                    );

                    app(
                        SkuPriceService::class
                    )->schedule(
                        $sku,
                        1000 * $i,
                        1500,
                        false,
                        $at->subHour(),
                        null,
                    );

                    $products[] =
                        $product;

                    $skus[] =
                        $sku;

                    $items[] =
                        $item;
                }

                $cart =
                    app(
                        CartCheckoutReservationService::class
                    )->begin(
                        $cart,
                        $reservationExpiresAt,
                    );

                return [
                    'tenant' => $tenant,

                    'instance' => $instance,

                    'cart' => $cart,

                    'location' => $location,

                    'products' => $products,

                    'skus' => $skus,

                    'items' => $items,

                    'at' => $at,

                    'reservation_expires_at' => $reservationExpiresAt,
                ];
            },
        );
    }

    public function test_conversion_is_atomic_and_snapshots_order_financial_catalog_and_customer_data(): void
    {
        $fixture =
            $this->fixture();

        $tenant =
            $fixture['tenant'];

        $cart =
            $fixture['cart'];

        $sku =
            $fixture['skus'][0];

        $cartItem =
            $fixture['items'][0];

        $at =
            $fixture['at'];

        $order =
            $this->inTenant(
                $tenant,
                fn (): Order => app(
                    OrderConversionService::class
                )->convert(
                    $cart,
                    $at,
                    $at->addMinutes(5),
                    $this->shipping(
                        $at
                    ),
                    $this->checkoutSnapshot(),
                ),
            );

        $this->assertSame(
            OrderStatus::PENDING,
            $order->status,
        );

        $this->assertSame(
            'SAR',
            $order->currency_code,
        );

        /*
         * One line:
         *
         * net      2000
         * VAT       300
         *
         * Shipping:
         * net       500
         * VAT        75
         *
         * total     2875
         */
        $this->assertSame(
            2000,
            $order->subtotal_minor,
        );

        $this->assertSame(
            0,
            $order->discount_minor,
        );

        $this->assertSame(
            375,
            $order->tax_minor,
        );

        $this->assertSame(
            500,
            $order->shipping_minor,
        );

        $this->assertSame(
            2875,
            $order->total_minor,
        );

        $this->assertSame(
            'Guest Customer',
            $order->customer_name,
        );

        $this->assertSame(
            'Jeddah',
            $order
                ->shipping_address_snapshot[
                    'city'
                ],
        );

        $this->assertCount(
            1,
            $order->items,
        );

        $orderItem =
            $order->items->first();

        $this->assertSame(
            $sku->id,
            (int) $orderItem->sku_id,
        );

        $this->assertSame(
            'SKU-1',
            $orderItem
                ->sku_code_snapshot,
        );

        $this->assertSame(
            'BAR-1',
            $orderItem
                ->barcode_snapshot,
        );

        $this->assertSame(
            'Product 1',
            $orderItem
                ->product_name_en_snapshot,
        );

        $this->assertSame(
            'Pack 1',
            $orderItem
                ->sku_name_en_snapshot,
        );

        $this->assertSame(
            2,
            $orderItem->quantity,
        );

        $this->assertSame(
            1000,
            $orderItem
                ->unit_net_minor,
        );

        $this->assertSame(
            2000,
            $orderItem
                ->line_subtotal_minor,
        );

        $this->assertSame(
            300,
            $orderItem
                ->tax_minor,
        );

        $this->assertSame(
            2300,
            $orderItem
                ->line_total_minor,
        );

        $this->inTenant(
            $tenant,
            function () use (
                $cart,
                $at,
                $cartItem,
                $orderItem,
                $sku,
                $fixture,
            ): void {
                $cart->refresh();

                $this->assertSame(
                    CartStatus::CONVERTED,
                    $cart->status,
                );

                $this->assertSame(
                    $at->getTimestamp(),
                    $cart
                        ->converted_at
                        ->getTimestamp(),
                );

                $this->assertNull(
                    $cart
                        ->inventory_reserved_until
                );

                $this->assertNull(
                    InventoryReservation::query()
                        ->where(
                            'reference_type',
                            'cart_item',
                        )
                        ->where(
                            'reference_id',
                            $cartItem->public_id,
                        )
                        ->where(
                            'status',
                            InventoryReservationStatus::ACTIVE,
                        )
                        ->first()
                );

                $reservation =
                    InventoryReservation::query()
                        ->where(
                            'reference_type',
                            'order_item',
                        )
                        ->where(
                            'reference_id',
                            $orderItem->public_id,
                        )
                        ->where(
                            'status',
                            InventoryReservationStatus::ACTIVE,
                        )
                        ->firstOrFail();

                $this->assertSame(
                    2,
                    $reservation->quantity,
                );

                $this->assertSame(
                    $fixture[
                        'reservation_expires_at'
                    ]->getTimestamp(),
                    $reservation
                        ->expires_at
                        ->getTimestamp(),
                );

                /*
                 * Ownership changed, ATS did not.
                 */
                $this->assertSame(
                    8,
                    app(
                        InventoryAvailabilityService::class
                    )->availableToSell(
                        $sku,
                        $fixture[
                            'location'
                        ],
                    ),
                );
            },
        );
    }

    public function test_conversion_replay_returns_same_order_without_duplication(): void
    {
        $fixture =
            $this->fixture();

        $tenant =
            $fixture['tenant'];

        $cart =
            $fixture['cart'];

        $at =
            $fixture['at'];

        $first =
            $this->inTenant(
                $tenant,
                fn (): Order => app(
                    OrderConversionService::class
                )->convert(
                    $cart,
                    $at,
                    $at->addMinutes(5),
                    $this->shipping(
                        $at
                    ),
                    $this->checkoutSnapshot(),
                ),
            );

        $differentSnapshot =
            new OrderCheckoutSnapshot(
                customerName: 'Changed Retry Name',

                customerEmail: 'retry@example.com',
            );

        $replay =
            $this->inTenant(
                $tenant,
                fn (): Order => app(
                    OrderConversionService::class
                )->convert(
                    $cart,
                    $at->addSecond(),
                    $at->addMinutes(6),
                    $this->shipping(
                        $at
                    ),
                    $differentSnapshot,
                ),
            );

        $this->assertSame(
            $first->id,
            $replay->id,
        );

        $this->assertSame(
            'Guest Customer',
            $replay->customer_name,
        );

        $this->inTenant(
            $tenant,
            function (): void {
                $this->assertSame(
                    1,
                    Order::query()
                        ->count(),
                );

                $this->assertSame(
                    1,
                    OrderItem::query()
                        ->count(),
                );

                $this->assertSame(
                    1,
                    InventoryReservation::query()
                        ->where(
                            'status',
                            InventoryReservationStatus::ACTIVE,
                        )
                        ->count(),
                );
            },
        );
    }

    public function test_failure_on_later_reservation_rolls_back_entire_conversion(): void
    {
        $fixture =
            $this->fixture(
                lineCount: 2
            );

        $tenant =
            $fixture['tenant'];

        $cart =
            $fixture['cart'];

        $at =
            $fixture['at'];

        $firstItem =
            $fixture['items'][0];

        $secondItem =
            $fixture['items'][1];

        $secondSku =
            $fixture['skus'][1];

        /*
         * Simulate loss of the second checkout hold before
         * conversion starts.
         *
         * The first line will transfer successfully inside
         * conversion, then the second line will fail.
         */
        $this->inTenant(
            $tenant,
            function () use (
                $secondSku,
                $fixture,
                $secondItem,
            ): void {
                app(
                    InventoryReservationService::class
                )->releaseReference(
                    $secondSku,
                    $fixture[
                        'location'
                    ],
                    'cart_item',
                    $secondItem
                        ->public_id,
                );
            },
        );

        try {
            $this->inTenant(
                $tenant,
                fn () => app(
                    OrderConversionService::class
                )->convert(
                    $cart,
                    $at,
                    $at->addMinutes(5),
                    $this->shipping(
                        $at
                    ),
                    $this->checkoutSnapshot(),
                ),
            );

            $this->fail(
                'Expected conversion to roll back.'
            );
        } catch (
            LogicException
        ) {
            $this->addToAssertionCount(
                1
            );
        }

        $this->inTenant(
            $tenant,
            function () use (
                $cart,
                $firstItem,
                $secondItem,
            ): void {
                $this->assertSame(
                    0,
                    Order::query()
                        ->count(),
                );

                $this->assertSame(
                    0,
                    OrderItem::query()
                        ->count(),
                );

                $cart->refresh();

                $this->assertSame(
                    CartStatus::ACTIVE,
                    $cart->status,
                );

                $this->assertNull(
                    $cart->converted_at
                );

                $this->assertNotNull(
                    $cart
                        ->inventory_reserved_until
                );

                /*
                 * First transfer happened before the second
                 * line failed, but transaction rollback must
                 * restore its Cart ownership.
                 */
                $firstReservation =
                    InventoryReservation::query()
                        ->where(
                            'reference_type',
                            'cart_item',
                        )
                        ->where(
                            'reference_id',
                            $firstItem
                                ->public_id,
                        )
                        ->where(
                            'status',
                            InventoryReservationStatus::ACTIVE,
                        )
                        ->first();

                $this->assertNotNull(
                    $firstReservation
                );

                $releasedSecond =
                    InventoryReservation::query()
                        ->where(
                            'reference_type',
                            'cart_item',
                        )
                        ->where(
                            'reference_id',
                            $secondItem
                                ->public_id,
                        )
                        ->where(
                            'status',
                            InventoryReservationStatus::RELEASED,
                        )
                        ->first();

                $this->assertNotNull(
                    $releasedSecond
                );
            },
        );
    }

    public function test_conversion_requires_active_checkout_reservation_window(): void
    {
        $fixture =
            $this->fixture();

        $tenant =
            $fixture['tenant'];

        $cart =
            $fixture['cart'];

        $at =
            $fixture['at'];

        $this->inTenant(
            $tenant,
            function () use (
                $cart
            ): void {
                $cart
                    ->inventory_reserved_until =
                        null;

                $cart->save();
            },
        );

        try {
            $this->inTenant(
                $tenant,
                fn () => app(
                    OrderConversionService::class
                )->convert(
                    $cart,
                    $at,
                    $at->addMinutes(5),
                    $this->shipping(
                        $at
                    ),
                    $this->checkoutSnapshot(),
                ),
            );

            $this->fail(
                'Expected conversion without checkout window to fail.'
            );
        } catch (
            LogicException
        ) {
            $this->addToAssertionCount(
                1
            );
        }

        $this->inTenant(
            $tenant,
            function (): void {
                $this->assertSame(
                    0,
                    Order::query()
                        ->count(),
                );

                $reservation =
                    InventoryReservation::query()
                        ->where(
                            'status',
                            InventoryReservationStatus::ACTIVE,
                        )
                        ->first();

                $this->assertNotNull(
                    $reservation
                );

                $this->assertSame(
                    'cart_item',
                    $reservation
                        ->reference_type,
                );
            },
        );
    }
}
