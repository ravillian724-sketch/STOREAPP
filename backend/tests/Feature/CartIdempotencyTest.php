<?php

namespace Tests\Feature;

use App\Exceptions\Cart\CartIdempotencyConflictException;
use App\Models\AppInstance;
use App\Models\Cart;
use App\Models\CartMutationReceipt;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\Sku;
use App\Models\Tenant;
use App\Services\Cart\CartService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class CartIdempotencyTest extends TestCase
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
        ?AppInstance $instance = null,
    ): Cart {
        $instance ??=
            $this->appInstance(
                $tenant
            );

        return $this->inTenant(
            $tenant,
            fn (): Cart => app(CartService::class)
                ->create(
                    $instance,
                    now()->addDays(30),
                )
                ->cart,
        );
    }

    private function inventory(
        Tenant $tenant,
        string $code = 'SKU-A',
    ): array {
        return $this->inTenant(
            $tenant,
            function () use ($code): array {
                $product =
                    Product::query()->create([
                        'name_ar' => $code,
                        'name_en' => $code,
                        'is_active' => true,
                    ]);

                $sku =
                    Sku::query()->create([
                        'product_id' => $product->id,

                        'code' => $code,

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
                    $sku,
                    $location,
                ];
            },
        );
    }

    public function test_same_add_item_key_is_exactly_once(): void
    {
        $tenant =
            $this->tenant();

        $cart =
            $this->cart($tenant);

        [
            $sku,
            $location,
        ] = $this->inventory(
            $tenant
        );

        $this->inTenant(
            $tenant,
            function () use (
                $cart,
                $sku,
                $location,
            ): void {
                $service =
                    app(CartService::class);

                $first =
                    $service->addItem(
                        $cart,
                        $sku,
                        $location,
                        2,
                        'request-001',
                    );

                $replay =
                    $service->addItem(
                        $cart,
                        $sku,
                        $location,
                        2,
                        'request-001',
                    );

                $this->assertSame(
                    $first->public_id,
                    $replay->public_id,
                );

                $this->assertSame(
                    2,
                    $replay->quantity,
                );

                $this->assertSame(
                    1,
                    CartMutationReceipt::query()
                        ->count(),
                );
            },
        );
    }

    public function test_same_key_with_different_payload_is_rejected_without_mutation(): void
    {
        $tenant =
            $this->tenant();

        $cart =
            $this->cart($tenant);

        [
            $sku,
            $location,
        ] = $this->inventory(
            $tenant
        );

        $this->inTenant(
            $tenant,
            function () use (
                $cart,
                $sku,
                $location,
            ): void {
                $service =
                    app(CartService::class);

                $item =
                    $service->addItem(
                        $cart,
                        $sku,
                        $location,
                        2,
                        'request-001',
                    );

                try {
                    $service->addItem(
                        $cart,
                        $sku,
                        $location,
                        3,
                        'request-001',
                    );

                    $this->fail(
                        'Expected idempotency conflict.'
                    );
                } catch (
                    CartIdempotencyConflictException
                ) {
                    //
                }

                $this->assertSame(
                    2,
                    $item
                        ->refresh()
                        ->quantity,
                );

                $this->assertSame(
                    1,
                    CartMutationReceipt::query()
                        ->count(),
                );
            },
        );
    }

    public function test_different_keys_apply_independent_mutations(): void
    {
        $tenant =
            $this->tenant();

        $cart =
            $this->cart($tenant);

        [
            $sku,
            $location,
        ] = $this->inventory(
            $tenant
        );

        $this->inTenant(
            $tenant,
            function () use (
                $cart,
                $sku,
                $location,
            ): void {
                $service =
                    app(CartService::class);

                $service->addItem(
                    $cart,
                    $sku,
                    $location,
                    2,
                    'request-001',
                );

                $item =
                    $service->addItem(
                        $cart,
                        $sku,
                        $location,
                        3,
                        'request-002',
                    );

                $this->assertSame(
                    5,
                    $item->quantity,
                );

                $this->assertSame(
                    2,
                    CartMutationReceipt::query()
                        ->count(),
                );
            },
        );
    }

    public function test_same_key_is_reusable_in_different_carts(): void
    {
        $tenant =
            $this->tenant();

        $instance =
            $this->appInstance(
                $tenant
            );

        $cartA =
            $this->cart(
                $tenant,
                $instance,
            );

        $cartB =
            $this->cart(
                $tenant,
                $instance,
            );

        [
            $sku,
            $location,
        ] = $this->inventory(
            $tenant
        );

        $this->inTenant(
            $tenant,
            function () use (
                $cartA,
                $cartB,
                $sku,
                $location,
            ): void {
                $service =
                    app(CartService::class);

                $service->addItem(
                    $cartA,
                    $sku,
                    $location,
                    1,
                    'same-client-key',
                );

                $service->addItem(
                    $cartB,
                    $sku,
                    $location,
                    1,
                    'same-client-key',
                );

                $this->assertSame(
                    2,
                    CartMutationReceipt::query()
                        ->count(),
                );
            },
        );
    }

    public function test_replay_does_not_require_inventory_to_remain_active(): void
    {
        $tenant =
            $this->tenant();

        $cart =
            $this->cart($tenant);

        [
            $sku,
            $location,
        ] = $this->inventory(
            $tenant
        );

        $this->inTenant(
            $tenant,
            function () use (
                $cart,
                $sku,
                $location,
            ): void {
                $service =
                    app(CartService::class);

                $first =
                    $service->addItem(
                        $cart,
                        $sku,
                        $location,
                        2,
                        'request-001',
                    );

                $sku->update([
                    'is_active' => false,
                ]);

                $location->update([
                    'is_active' => false,
                ]);

                $replay =
                    $service->addItem(
                        $cart,
                        $sku,
                        $location,
                        2,
                        'request-001',
                    );

                $this->assertSame(
                    $first->public_id,
                    $replay->public_id,
                );

                $this->assertSame(
                    2,
                    $replay->quantity,
                );
            },
        );
    }

    public function test_database_blocks_duplicate_receipt_key_inside_same_cart(): void
    {
        $tenant =
            $this->tenant();

        $cart =
            $this->cart($tenant);

        [
            $sku,
            $location,
        ] = $this->inventory(
            $tenant
        );

        $this->inTenant(
            $tenant,
            function () use (
                $cart,
                $sku,
                $location,
            ): void {
                $item =
                    app(CartService::class)
                        ->addItem(
                            $cart,
                            $sku,
                            $location,
                            1,
                            'request-001',
                        );

                $this->expectException(
                    QueryException::class
                );

                CartMutationReceipt::query()
                    ->create([
                        'cart_id' => $cart->id,

                        'idempotency_key' => 'request-001',

                        'operation' => 'add_item',

                        'request_hash' => str_repeat(
                            'b',
                            64,
                        ),

                        'result_item_public_id' => $item->public_id,
                    ]);
            },
        );
    }

    public function test_empty_idempotency_key_is_rejected(): void
    {
        $tenant =
            $this->tenant();

        $cart =
            $this->cart($tenant);

        [
            $sku,
            $location,
        ] = $this->inventory(
            $tenant
        );

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->inTenant(
            $tenant,
            fn () => app(CartService::class)
                ->addItem(
                    $cart,
                    $sku,
                    $location,
                    1,
                    '   ',
                ),
        );
    }

    public function test_oversized_idempotency_key_is_rejected(): void
    {
        $tenant =
            $this->tenant();

        $cart =
            $this->cart($tenant);

        [
            $sku,
            $location,
        ] = $this->inventory(
            $tenant
        );

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->inTenant(
            $tenant,
            fn () => app(CartService::class)
                ->addItem(
                    $cart,
                    $sku,
                    $location,
                    1,
                    str_repeat(
                        'x',
                        121,
                    ),
                ),
        );
    }
}
