<?php

namespace Tests\Feature;

use App\Exceptions\Cart\CartNotAccessibleException;
use App\Exceptions\Cart\CartNotMutableException;
use App\Models\AppInstance;
use App\Models\CartItem;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\Sku;
use App\Models\Tenant;
use App\Services\Cart\CartService;
use App\Support\Cart\CartStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class CartServiceTest extends TestCase
{
    use RefreshDatabase;

    private CartService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service =
            app(CartService::class);
    }

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
        bool $active = true,
    ): AppInstance {
        return AppInstance::query()->create([
            'tenant_id' => $tenant->id,

            'channel' => 'mobile',

            'is_active' => $active,
        ]);
    }

    private function sku(
        Tenant $tenant,
        string $code,
        bool $active = true,
        bool $tracked = true,
    ): Sku {
        return $this->inTenant(
            $tenant,
            function () use (
                $code,
                $active,
                $tracked,
            ): Sku {
                $product =
                    Product::query()->create([
                        'name_ar' => $code,

                        'name_en' => $code,

                        'is_active' => true,
                    ]);

                return Sku::query()->create([
                    'product_id' => $product->id,

                    'code' => $code,

                    'track_inventory' => $tracked,

                    'is_active' => $active,
                ]);
            },
        );
    }

    private function location(
        Tenant $tenant,
        string $code,
        bool $active = true,
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

                    'is_active' => $active,
                ]),
        );
    }

    public function test_create_returns_guest_token_but_only_hash_is_persisted(): void
    {
        $tenant =
            $this->tenant('Tenant A');

        $instance =
            $this->appInstance($tenant);

        $created =
            $this->inTenant(
                $tenant,
                fn () => $this->service->create(
                    $instance
                ),
            );

        $this->assertSame(
            64,
            strlen(
                $created->token
            ),
        );

        $this->assertNotSame(
            $created->token,
            $created->cart->token_hash,
        );

        $this->assertSame(
            hash(
                'sha256',
                $created->token,
            ),
            $created->cart->token_hash,
        );

        $this->assertArrayNotHasKey(
            'token_hash',
            $created->cart->toArray(),
        );
    }

    public function test_resolve_requires_correct_cart_token(): void
    {
        $tenant =
            $this->tenant('Tenant A');

        $instance =
            $this->appInstance($tenant);

        $created =
            $this->inTenant(
                $tenant,
                fn () => $this->service->create(
                    $instance
                ),
            );

        $resolved =
            $this->inTenant(
                $tenant,
                fn () => $this->service->resolve(
                    $created->cart->public_id,
                    $created->token,
                ),
            );

        $this->assertSame(
            $created->cart->id,
            $resolved->id,
        );

        $this->expectException(
            CartNotAccessibleException::class
        );

        $this->inTenant(
            $tenant,
            fn () => $this->service->resolve(
                $created->cart->public_id,
                str_repeat('f', 64),
            ),
        );
    }

    public function test_create_rejects_foreign_app_instance(): void
    {
        $tenantA =
            $this->tenant('Tenant A');

        $tenantB =
            $this->tenant('Tenant B');

        $foreign =
            $this->appInstance(
                $tenantB
            );

        $this->expectException(
            LogicException::class
        );

        $this->inTenant(
            $tenantA,
            fn () => $this->service->create(
                $foreign
            ),
        );
    }

    public function test_add_item_creates_line_and_second_add_increments_quantity(): void
    {
        $tenant =
            $this->tenant('Tenant A');

        $instance =
            $this->appInstance($tenant);

        $sku =
            $this->sku(
                $tenant,
                'SKU-A',
            );

        $location =
            $this->location(
                $tenant,
                'MAIN',
            );

        $created =
            $this->inTenant(
                $tenant,
                fn () => $this->service->create(
                    $instance
                ),
            );

        $item =
            $this->inTenant(
                $tenant,
                fn () => $this->service->addItem(
                    $created->cart,
                    $sku,
                    $location,
                    2,
                ),
            );

        $this->assertSame(
            2,
            $item->quantity,
        );

        $item =
            $this->inTenant(
                $tenant,
                fn () => $this->service->addItem(
                    $created->cart,
                    $sku,
                    $location,
                    3,
                ),
            );

        $this->assertSame(
            5,
            $item->quantity,
        );

        $this->inTenant(
            $tenant,
            function (): void {
                $this->assertSame(
                    1,
                    CartItem::query()->count(),
                );
            },
        );
    }

    public function test_set_quantity_updates_absolute_quantity(): void
    {
        $tenant =
            $this->tenant('Tenant A');

        $instance =
            $this->appInstance($tenant);

        $sku =
            $this->sku(
                $tenant,
                'SKU-A',
            );

        $location =
            $this->location(
                $tenant,
                'MAIN',
            );

        $created =
            $this->inTenant(
                $tenant,
                fn () => $this->service->create(
                    $instance
                ),
            );

        $item =
            $this->inTenant(
                $tenant,
                fn () => $this->service->addItem(
                    $created->cart,
                    $sku,
                    $location,
                    2,
                ),
            );

        $updated =
            $this->inTenant(
                $tenant,
                fn () => $this->service->setQuantity(
                    $created->cart,
                    $item,
                    7,
                ),
            );

        $this->assertSame(
            7,
            $updated->quantity,
        );
    }

    public function test_remove_item_deletes_only_owned_cart_line(): void
    {
        $tenant =
            $this->tenant('Tenant A');

        $instance =
            $this->appInstance($tenant);

        $sku =
            $this->sku(
                $tenant,
                'SKU-A',
            );

        $location =
            $this->location(
                $tenant,
                'MAIN',
            );

        $created =
            $this->inTenant(
                $tenant,
                fn () => $this->service->create(
                    $instance
                ),
            );

        $item =
            $this->inTenant(
                $tenant,
                fn () => $this->service->addItem(
                    $created->cart,
                    $sku,
                    $location,
                    1,
                ),
            );

        $this->inTenant(
            $tenant,
            fn () => $this->service->removeItem(
                $created->cart,
                $item,
            ),
        );

        $this->inTenant(
            $tenant,
            function (): void {
                $this->assertSame(
                    0,
                    CartItem::query()->count(),
                );
            },
        );
    }

    public function test_non_positive_quantities_are_rejected(): void
    {
        $tenant =
            $this->tenant('Tenant A');

        $instance =
            $this->appInstance($tenant);

        $sku =
            $this->sku(
                $tenant,
                'SKU-A',
            );

        $location =
            $this->location(
                $tenant,
                'MAIN',
            );

        $created =
            $this->inTenant(
                $tenant,
                fn () => $this->service->create(
                    $instance
                ),
            );

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->inTenant(
            $tenant,
            fn () => $this->service->addItem(
                $created->cart,
                $sku,
                $location,
                0,
            ),
        );
    }

    public function test_untracked_inventory_is_rejected(): void
    {
        $tenant =
            $this->tenant('Tenant A');

        $instance =
            $this->appInstance($tenant);

        $sku =
            $this->sku(
                $tenant,
                'SKU-A',
                active: true,
                tracked: false,
            );

        $location =
            $this->location(
                $tenant,
                'MAIN',
            );

        $created =
            $this->inTenant(
                $tenant,
                fn () => $this->service->create(
                    $instance
                ),
            );

        $this->expectException(
            LogicException::class
        );

        $this->inTenant(
            $tenant,
            fn () => $this->service->addItem(
                $created->cart,
                $sku,
                $location,
                1,
            ),
        );
    }

    public function test_foreign_tenant_inventory_is_rejected(): void
    {
        $tenantA =
            $this->tenant('Tenant A');

        $tenantB =
            $this->tenant('Tenant B');

        $instance =
            $this->appInstance($tenantA);

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

        $created =
            $this->inTenant(
                $tenantA,
                fn () => $this->service->create(
                    $instance
                ),
            );

        $this->expectException(
            LogicException::class
        );

        $this->inTenant(
            $tenantA,
            fn () => $this->service->addItem(
                $created->cart,
                $foreignSku,
                $foreignLocation,
                1,
            ),
        );
    }

    public function test_terminal_cart_cannot_be_modified(): void
    {
        $tenant =
            $this->tenant('Tenant A');

        $instance =
            $this->appInstance($tenant);

        $sku =
            $this->sku(
                $tenant,
                'SKU-A',
            );

        $location =
            $this->location(
                $tenant,
                'MAIN',
            );

        $created =
            $this->inTenant(
                $tenant,
                fn () => $this->service->create(
                    $instance
                ),
            );

        $this->inTenant(
            $tenant,
            function () use (
                $created
            ): void {
                $created->cart->update([
                    'status' => CartStatus::ABANDONED,

                    'abandoned_at' => now(),
                ]);
            },
        );

        $this->expectException(
            CartNotMutableException::class
        );

        $this->inTenant(
            $tenant,
            fn () => $this->service->addItem(
                $created->cart,
                $sku,
                $location,
                1,
            ),
        );
    }

    public function test_cart_item_from_another_cart_cannot_be_modified(): void
    {
        $tenant =
            $this->tenant('Tenant A');

        $instance =
            $this->appInstance($tenant);

        $sku =
            $this->sku(
                $tenant,
                'SKU-A',
            );

        $location =
            $this->location(
                $tenant,
                'MAIN',
            );

        $first =
            $this->inTenant(
                $tenant,
                fn () => $this->service->create(
                    $instance
                ),
            );

        $second =
            $this->inTenant(
                $tenant,
                fn () => $this->service->create(
                    $instance
                ),
            );

        $item =
            $this->inTenant(
                $tenant,
                fn () => $this->service->addItem(
                    $first->cart,
                    $sku,
                    $location,
                    1,
                ),
            );

        $this->expectException(
            LogicException::class
        );

        $this->inTenant(
            $tenant,
            fn () => $this->service->setQuantity(
                $second->cart,
                $item,
                2,
            ),
        );
    }

    public function test_create_rejects_inactive_app_instance(): void
    {
        $tenant =
            $this->tenant('Tenant A');

        $instance =
            $this->appInstance(
                $tenant,
                active: false,
            );

        $this->expectException(
            LogicException::class
        );

        $this->inTenant(
            $tenant,
            fn () => $this->service->create(
                $instance
            ),
        );
    }

    public function test_inactive_sku_is_rejected(): void
    {
        $tenant =
            $this->tenant('Tenant A');

        $instance =
            $this->appInstance($tenant);

        $sku =
            $this->sku(
                $tenant,
                'SKU-INACTIVE',
                active: false,
                tracked: true,
            );

        $location =
            $this->location(
                $tenant,
                'MAIN',
            );

        $created =
            $this->inTenant(
                $tenant,
                fn () => $this->service->create(
                    $instance
                ),
            );

        $this->expectException(
            LogicException::class
        );

        $this->inTenant(
            $tenant,
            fn () => $this->service->addItem(
                $created->cart,
                $sku,
                $location,
                1,
            ),
        );
    }

    public function test_inactive_location_is_rejected(): void
    {
        $tenant =
            $this->tenant('Tenant A');

        $instance =
            $this->appInstance($tenant);

        $sku =
            $this->sku(
                $tenant,
                'SKU-A',
            );

        $location =
            $this->location(
                $tenant,
                'INACTIVE',
                active: false,
            );

        $created =
            $this->inTenant(
                $tenant,
                fn () => $this->service->create(
                    $instance
                ),
            );

        $this->expectException(
            LogicException::class
        );

        $this->inTenant(
            $tenant,
            fn () => $this->service->addItem(
                $created->cart,
                $sku,
                $location,
                1,
            ),
        );
    }

    public function test_expired_cart_cannot_be_modified(): void
    {
        $tenant =
            $this->tenant('Tenant A');

        $instance =
            $this->appInstance($tenant);

        $sku =
            $this->sku(
                $tenant,
                'SKU-A',
            );

        $location =
            $this->location(
                $tenant,
                'MAIN',
            );

        $created =
            $this->inTenant(
                $tenant,
                fn () => $this->service->create(
                    $instance
                ),
            );

        $this->inTenant(
            $tenant,
            function () use (
                $created
            ): void {
                $created->cart->update([
                    'expires_at' => now()->subMinute(),
                ]);
            },
        );

        $this->expectException(
            CartNotMutableException::class
        );

        $this->inTenant(
            $tenant,
            fn () => $this->service->addItem(
                $created->cart,
                $sku,
                $location,
                1,
            ),
        );
    }

    public function test_cart_cannot_be_resolved_from_another_tenant(): void
    {
        $tenantA =
            $this->tenant('Tenant A');

        $tenantB =
            $this->tenant('Tenant B');

        $instance =
            $this->appInstance($tenantA);

        $created =
            $this->inTenant(
                $tenantA,
                fn () => $this->service->create(
                    $instance
                ),
            );

        $this->expectException(
            CartNotAccessibleException::class
        );

        $this->inTenant(
            $tenantB,
            fn () => $this->service->resolve(
                $created->cart->public_id,
                $created->token,
            ),
        );
    }
}
