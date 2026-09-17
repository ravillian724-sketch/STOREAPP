<?php

namespace Tests\Feature;

use App\Models\AppInstance;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Cart\CartService;
use App\Support\Order\OrderStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderCartAppInstanceCoherenceTest extends TestCase
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

    private function appInstance(
        Tenant $tenant,
        string $channel,
    ): AppInstance {
        return AppInstance::query()->create([
            'tenant_id' => $tenant->id,
            'channel' => $channel,
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

    private function createOrder(
        Tenant $tenant,
        AppInstance $instance,
        Cart $cart,
    ): Order {
        return $this->inTenant(
            $tenant,
            fn (): Order => Order::query()->create([
                'app_instance_id' => $instance->id,

                'cart_id' => $cart->id,

                'public_id' => (string) Str::uuid(),

                'status' => OrderStatus::PENDING,

                'currency_code' => 'SAR',
            ]),
        );
    }

    public function test_order_accepts_source_cart_app_instance(): void
    {
        $tenant =
            $this->tenant();

        $instance =
            $this->appInstance(
                $tenant,
                'mobile',
            );

        $cart =
            $this->cart(
                $tenant,
                $instance,
            );

        $order =
            $this->createOrder(
                $tenant,
                $instance,
                $cart,
            );

        $this->assertSame(
            $cart->id,
            (int) $order->cart_id,
        );

        $this->assertSame(
            $instance->id,
            (int) $order->app_instance_id,
        );
    }

    public function test_same_tenant_order_cannot_use_different_app_instance_than_cart(): void
    {
        $tenant =
            $this->tenant();

        $cartInstance =
            $this->appInstance(
                $tenant,
                'mobile',
            );

        $otherInstance =
            $this->appInstance(
                $tenant,
                'web',
            );

        $cart =
            $this->cart(
                $tenant,
                $cartInstance,
            );

        $this->expectException(
            QueryException::class
        );

        $this->createOrder(
            $tenant,
            $otherInstance,
            $cart,
        );
    }

    public function test_existing_order_cannot_be_repointed_to_different_same_tenant_app_instance(): void
    {
        $tenant =
            $this->tenant();

        $cartInstance =
            $this->appInstance(
                $tenant,
                'mobile',
            );

        $otherInstance =
            $this->appInstance(
                $tenant,
                'web',
            );

        $cart =
            $this->cart(
                $tenant,
                $cartInstance,
            );

        $order =
            $this->createOrder(
                $tenant,
                $cartInstance,
                $cart,
            );

        $this->expectException(
            QueryException::class
        );

        $this->inTenant(
            $tenant,
            function () use (
                $order,
                $otherInstance,
            ): void {
                $order->app_instance_id =
                    $otherInstance->id;

                $order->save();
            },
        );
    }
}
