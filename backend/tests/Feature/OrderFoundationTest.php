<?php

namespace Tests\Feature;

use App\Models\AppInstance;
use App\Models\Cart;
use App\Models\InventoryLocation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Sku;
use App\Models\Tenant;
use App\Services\Cart\CartService;
use App\Support\Order\OrderStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class OrderFoundationTest extends TestCase
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

    private function cart(
        Tenant $tenant,
        AppInstance $instance,
    ): Cart {
        return $this->inTenant(
            $tenant,
            fn (): Cart => app(
                CartService::class
            )->create(
                $instance,
                now()->addDay(),
            )->cart,
        );
    }

    private function order(
        Tenant $tenant,
        AppInstance $instance,
        Cart $cart,
    ): Order {
        return $this->inTenant(
            $tenant,
            fn (): Order => Order::query()
                ->create([
                    'app_instance_id' => $instance->id,

                    'cart_id' => $cart->id,

                    'public_id' => (string) Str::uuid(),

                    'status' => OrderStatus::PENDING,

                    'currency_code' => 'SAR',

                    'customer_name' => 'Guest Customer',

                    'customer_phone' => '+966500000000',

                    'customer_email' => 'guest@example.com',

                    'shipping_address_snapshot' => [
                        'city' => 'Jeddah',
                        'country_code' => 'SA',
                    ],

                    'subtotal_minor' => 2000,
                    'discount_minor' => 100,
                    'tax_minor' => 285,
                    'shipping_minor' => 500,
                    'total_minor' => 2685,
                ]),
        );
    }

    private function inventory(
        Tenant $tenant,
        string $code,
    ): array {
        return $this->inTenant(
            $tenant,
            function () use ($code): array {
                $product =
                    Product::query()->create([
                        'name_ar' => 'منتج '.$code,

                        'name_en' => 'Product '.$code,

                        'is_active' => true,
                    ]);

                $sku =
                    Sku::query()->create([
                        'product_id' => $product->id,

                        'code' => $code,

                        'barcode' => 'BAR-'.$code,

                        'name_ar' => 'عبوة',

                        'name_en' => 'Pack',

                        'track_inventory' => true,

                        'is_active' => true,
                    ]);

                $location =
                    InventoryLocation::query()
                        ->create([
                            'branch_id' => null,

                            'code' => 'LOC-'.$code,

                            'name_ar' => 'LOC-'.$code,

                            'name_en' => 'LOC-'.$code,

                            'type' => 'stock',

                            'is_active' => true,
                        ]);

                return [
                    $product,
                    $sku,
                    $location,
                ];
            },
        );
    }

    public function test_postgres_rejects_invalid_guest_order_access_hash(): void
    {
        if (
            DB::connection()->getDriverName()
            !== 'pgsql'
        ) {
            $this->markTestSkipped(
                'PostgreSQL-specific order token constraint test.'
            );
        }

        $tenant =
            $this->tenant('Tenant Token Guard');

        $instance =
            $this->appInstance(
                $tenant
            );

        $cart =
            $this->cart(
                $tenant,
                $instance,
            );

        $order =
            $this->order(
                $tenant,
                $instance,
                $cart,
            );

        $this->expectException(
            QueryException::class
        );

        $this->inTenant(
            $tenant,
            fn (): int => Order::query()
                ->whereKey($order->id)
                ->update([
                    'guest_access_token_hash' => 'not-a-valid-sha256-hash',
                ]),
        );
    }

    public function test_orders_fail_closed_without_tenant_context(): void
    {
        $tenant =
            $this->tenant('Tenant A');

        $instance =
            $this->appInstance(
                $tenant
            );

        $cart =
            $this->cart(
                $tenant,
                $instance,
            );

        $this->expectException(
            RuntimeException::class
        );

        Order::query()->create([
            'app_instance_id' => $instance->id,

            'cart_id' => $cart->id,

            'public_id' => (string) Str::uuid(),

            'status' => OrderStatus::PENDING,

            'currency_code' => 'SAR',
        ]);
    }

    public function test_order_and_item_snapshot_historical_values(): void
    {
        $tenant =
            $this->tenant('Tenant A');

        $instance =
            $this->appInstance(
                $tenant
            );

        $cart =
            $this->cart(
                $tenant,
                $instance,
            );

        [
            $product,
            $sku,
            $location,
        ] = $this->inventory(
            $tenant,
            'SKU-A',
        );

        $order =
            $this->order(
                $tenant,
                $instance,
                $cart,
            );

        $item =
            $this->inTenant(
                $tenant,
                fn (): OrderItem => OrderItem::query()
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

                        'unit_net_minor' => 1000,

                        'line_subtotal_minor' => 2000,

                        'discount_minor' => 100,

                        'tax_rate_bps' => 1500,

                        'tax_minor' => 285,

                        'line_total_minor' => 2185,
                    ]),
            );

        $tenant->update([
            'currency_code' => 'USD',
            'vat_rate' => 5,
        ]);

        $this->inTenant(
            $tenant,
            function () use (
                $product,
                $sku,
            ): void {
                $product->update([
                    'name_en' => 'Changed Product',
                ]);

                $sku->update([
                    'code' => 'CHANGED-SKU',
                ]);
            },
        );

        /*
         * Orders and order items are tenant-owned and
         * PostgreSQL FORCE RLS must remain active even
         * inside tests. Refresh historical snapshots only
         * while the owning tenant context is installed.
         */
        $this->inTenant(
            $tenant,
            function () use (
                $order,
                $item,
            ): void {
                $order->refresh();
                $item->refresh();
            },
        );

        $this->assertSame(
            'SAR',
            $order->currency_code,
        );

        $this->assertSame(
            'SKU-A',
            $item->sku_code_snapshot,
        );

        $this->assertSame(
            'Product SKU-A',
            $item
                ->product_name_en_snapshot,
        );

        $this->assertSame(
            2685,
            $order->total_minor,
        );

        $this->assertSame(
            2185,
            $item->line_total_minor,
        );

        $this->assertSame(
            'Jeddah',
            $order
                ->shipping_address_snapshot[
                    'city'
                ],
        );
    }

    public function test_cart_can_materialize_into_only_one_order(): void
    {
        $tenant =
            $this->tenant('Tenant A');

        $instance =
            $this->appInstance(
                $tenant
            );

        $cart =
            $this->cart(
                $tenant,
                $instance,
            );

        $this->order(
            $tenant,
            $instance,
            $cart,
        );

        $this->expectException(
            QueryException::class
        );

        $this->order(
            $tenant,
            $instance,
            $cart,
        );
    }

    public function test_order_cannot_reference_foreign_tenant_cart(): void
    {
        $tenantA =
            $this->tenant('Tenant A');

        $tenantB =
            $this->tenant('Tenant B');

        $instanceA =
            $this->appInstance(
                $tenantA
            );

        $instanceB =
            $this->appInstance(
                $tenantB
            );

        $foreignCart =
            $this->cart(
                $tenantB,
                $instanceB,
            );

        $this->expectException(
            QueryException::class
        );

        $this->inTenant(
            $tenantA,
            fn () => Order::query()
                ->create([
                    'app_instance_id' => $instanceA->id,

                    'cart_id' => $foreignCart->id,

                    'public_id' => (string) Str::uuid(),

                    'status' => OrderStatus::PENDING,

                    'currency_code' => 'SAR',
                ]),
        );
    }

    public function test_order_item_cannot_reference_foreign_tenant_inventory(): void
    {
        $tenantA =
            $this->tenant('Tenant A');

        $tenantB =
            $this->tenant('Tenant B');

        $instance =
            $this->appInstance(
                $tenantA
            );

        $cart =
            $this->cart(
                $tenantA,
                $instance,
            );

        $order =
            $this->order(
                $tenantA,
                $instance,
                $cart,
            );

        [
            $product,
            $foreignSku,
            $foreignLocation,
        ] = $this->inventory(
            $tenantB,
            'FOREIGN',
        );

        $this->expectException(
            QueryException::class
        );

        $this->inTenant(
            $tenantA,
            fn () => OrderItem::query()
                ->create([
                    'order_id' => $order->id,

                    'sku_id' => $foreignSku->id,

                    'location_id' => $foreignLocation->id,

                    'public_id' => (string) Str::uuid(),

                    'sku_code_snapshot' => $foreignSku->code,

                    'barcode_snapshot' => $foreignSku->barcode,

                    'product_name_ar_snapshot' => $product->name_ar,

                    'product_name_en_snapshot' => $product->name_en,

                    'sku_name_ar_snapshot' => $foreignSku->name_ar,

                    'sku_name_en_snapshot' => $foreignSku->name_en,

                    'quantity' => 1,

                    'unit_net_minor' => 1000,

                    'line_subtotal_minor' => 1000,

                    'discount_minor' => 0,

                    'tax_rate_bps' => 1500,

                    'tax_minor' => 150,

                    'line_total_minor' => 1150,
                ]),
        );
    }
}
