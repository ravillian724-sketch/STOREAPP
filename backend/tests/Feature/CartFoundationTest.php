<?php

namespace Tests\Feature;

use App\Models\AppInstance;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\Sku;
use App\Models\Tenant;
use App\Support\Cart\CartStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class CartFoundationTest extends TestCase
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

    private function appInstance(
        Tenant $tenant,
        string $channel = 'mobile',
    ): AppInstance {
        return AppInstance::query()->create([
            'tenant_id' => $tenant->id,

            'channel' => $channel,

            'is_active' => true,
        ]);
    }

    private function inTenant(
        Tenant $tenant,
        callable $callback,
    ): mixed {
        $context = app(
            TenantContext::class
        );

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

    private function sku(
        Tenant $tenant,
        string $code,
    ): Sku {
        return $this->inTenant(
            $tenant,
            function () use ($code): Sku {
                $product =
                    Product::query()->create([
                        'name_ar' => $code,

                        'name_en' => $code,

                        'is_active' => true,
                    ]);

                return Sku::query()->create([
                    'product_id' => $product->id,

                    'code' => $code,

                    'track_inventory' => true,

                    'is_active' => true,
                ]);
            },
        );
    }

    private function location(
        Tenant $tenant,
        string $code,
    ): InventoryLocation {
        return $this->inTenant(
            $tenant,
            fn (): InventoryLocation => InventoryLocation::query()
                ->create([
                    'branch_id' => null,

                    'code' => $code,

                    'name_ar' => $code,

                    'name_en' => $code,

                    'type' => 'stock',

                    'is_active' => true,
                ]),
        );
    }

    private function cart(
        Tenant $tenant,
        AppInstance $instance,
    ): Cart {
        return $this->inTenant(
            $tenant,
            fn (): Cart => Cart::query()->create([
                'app_instance_id' => $instance->id,

                'public_id' => (string) Str::uuid(),

                'token_hash' => hash(
                    'sha256',
                    Str::random(64),
                ),

                'status' => CartStatus::ACTIVE,

                'expires_at' => now()->addDays(30),
            ]),
        );
    }

    public function test_cart_creation_fails_closed_without_tenant_context(): void
    {
        $tenant = $this->tenant(
            'Tenant A'
        );

        $instance =
            $this->appInstance(
                $tenant
            );

        $this->expectException(
            RuntimeException::class
        );

        Cart::query()->create([
            'app_instance_id' => $instance->id,

            'public_id' => (string) Str::uuid(),

            'token_hash' => hash(
                'sha256',
                Str::random(64),
            ),

            'status' => CartStatus::ACTIVE,
        ]);
    }

    public function test_cart_is_owned_by_active_tenant(): void
    {
        $tenant = $this->tenant(
            'Tenant A'
        );

        $instance =
            $this->appInstance(
                $tenant
            );

        $cart = $this->cart(
            $tenant,
            $instance,
        );

        $this->assertSame(
            $tenant->id,
            (int) $cart->tenant_id,
        );

        $this->assertSame(
            $instance->id,
            (int) $cart->app_instance_id,
        );

        $this->assertSame(
            CartStatus::ACTIVE,
            $cart->status,
        );

        $this->assertArrayNotHasKey(
            'token_hash',
            $cart->toArray(),
        );
    }

    public function test_cart_cannot_reference_foreign_tenant_app_instance(): void
    {
        $tenantA = $this->tenant(
            'Tenant A'
        );

        $tenantB = $this->tenant(
            'Tenant B'
        );

        $foreignInstance =
            $this->appInstance(
                $tenantB
            );

        $blocked = false;

        $this->inTenant(
            $tenantA,
            function () use (
                $foreignInstance,
                &$blocked,
            ): void {
                try {
                    Cart::query()->create([
                        'app_instance_id' => $foreignInstance->id,

                        'public_id' => (string) Str::uuid(),

                        'token_hash' => hash(
                            'sha256',
                            Str::random(64),
                        ),

                        'status' => CartStatus::ACTIVE,
                    ]);
                } catch (QueryException) {
                    $blocked = true;
                }
            },
        );

        $this->assertTrue(
            $blocked
        );
    }

    public function test_cart_item_cannot_reference_foreign_tenant_inventory(): void
    {
        $tenantA = $this->tenant(
            'Tenant A'
        );

        $tenantB = $this->tenant(
            'Tenant B'
        );

        $instance =
            $this->appInstance(
                $tenantA
            );

        $cart = $this->cart(
            $tenantA,
            $instance,
        );

        $foreignSku =
            $this->sku(
                $tenantB,
                'SKU-B',
            );

        $foreignLocation =
            $this->location(
                $tenantB,
                'B-MAIN',
            );

        $blocked = false;

        $this->inTenant(
            $tenantA,
            function () use (
                $cart,
                $foreignSku,
                $foreignLocation,
                &$blocked,
            ): void {
                try {
                    CartItem::query()
                        ->create([
                            'cart_id' => $cart->id,

                            'sku_id' => $foreignSku->id,

                            'location_id' => $foreignLocation->id,

                            'public_id' => (string) Str::uuid(),

                            'quantity' => 1,
                        ]);
                } catch (QueryException) {
                    $blocked = true;
                }
            },
        );

        $this->assertTrue(
            $blocked
        );
    }

    public function test_same_sku_location_can_only_have_one_line_per_cart(): void
    {
        $tenant = $this->tenant(
            'Tenant A'
        );

        $instance =
            $this->appInstance(
                $tenant
            );

        $cart = $this->cart(
            $tenant,
            $instance,
        );

        $sku = $this->sku(
            $tenant,
            'SKU-A',
        );

        $location =
            $this->location(
                $tenant,
                'MAIN',
            );

        $this->inTenant(
            $tenant,
            function () use (
                $cart,
                $sku,
                $location,
            ): void {
                CartItem::query()
                    ->create([
                        'cart_id' => $cart->id,

                        'sku_id' => $sku->id,

                        'location_id' => $location->id,

                        'public_id' => (string) Str::uuid(),

                        'quantity' => 1,
                    ]);

                $this->expectException(
                    QueryException::class
                );

                CartItem::query()
                    ->create([
                        'cart_id' => $cart->id,

                        'sku_id' => $sku->id,

                        'location_id' => $location->id,

                        'public_id' => (string) Str::uuid(),

                        'quantity' => 2,
                    ]);
            },
        );
    }

    public function test_cart_and_items_are_tenant_isolated(): void
    {
        $tenantA = $this->tenant(
            'Tenant A'
        );

        $tenantB = $this->tenant(
            'Tenant B'
        );

        $instanceA =
            $this->appInstance(
                $tenantA
            );

        $instanceB =
            $this->appInstance(
                $tenantB
            );

        $cartA = $this->cart(
            $tenantA,
            $instanceA,
        );

        $cartB = $this->cart(
            $tenantB,
            $instanceB,
        );

        $skuA = $this->sku(
            $tenantA,
            'SKU-A',
        );

        $skuB = $this->sku(
            $tenantB,
            'SKU-B',
        );

        $locationA =
            $this->location(
                $tenantA,
                'A-MAIN',
            );

        $locationB =
            $this->location(
                $tenantB,
                'B-MAIN',
            );

        $this->inTenant(
            $tenantA,
            function () use (
                $cartA,
                $skuA,
                $locationA,
            ): void {
                CartItem::query()
                    ->create([
                        'cart_id' => $cartA->id,

                        'sku_id' => $skuA->id,

                        'location_id' => $locationA->id,

                        'public_id' => (string) Str::uuid(),

                        'quantity' => 1,
                    ]);
            },
        );

        $this->inTenant(
            $tenantB,
            function () use (
                $cartB,
                $skuB,
                $locationB,
            ): void {
                CartItem::query()
                    ->create([
                        'cart_id' => $cartB->id,

                        'sku_id' => $skuB->id,

                        'location_id' => $locationB->id,

                        'public_id' => (string) Str::uuid(),

                        'quantity' => 1,
                    ]);
            },
        );

        $this->inTenant(
            $tenantA,
            function (): void {
                $this->assertSame(
                    1,
                    Cart::query()->count(),
                );

                $this->assertSame(
                    1,
                    CartItem::query()->count(),
                );
            },
        );

        $this->inTenant(
            $tenantB,
            function (): void {
                $this->assertSame(
                    1,
                    Cart::query()->count(),
                );

                $this->assertSame(
                    1,
                    CartItem::query()->count(),
                );
            },
        );
    }
}
