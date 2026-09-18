<?php

namespace Tests\Feature;

use App\Models\AppInstance;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\InventoryLocation;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\Sku;
use App\Models\Tenant;
use App\Services\AppInstanceCredentialService;
use App\Services\Inventory\InventoryReservationService;
use App\Services\Inventory\StockLedgerService;
use App\Services\Pricing\SkuPriceService;
use App\Support\Inventory\InventoryMovementType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StorefrontCartApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{Tenant, AppInstance, string}
     */
    private function storefront(
        string $name,
    ): array {
        $tenant = Tenant::query()->create([
            'name_ar' => $name,
            'name_en' => $name,
            'country_code' => 'SA',
            'currency_code' => 'SAR',
            'vat_rate' => 15,
            'primary_color' => '#006C67',
            'secondary_color' => '#0F172A',
            'is_active' => true,
        ]);

        $instance = AppInstance::query()->create([
            'tenant_id' => $tenant->id,
            'channel' => 'mobile',
            'is_active' => true,
        ]);

        $issued = app(
            AppInstanceCredentialService::class
        )->issue($instance);

        return [
            $tenant,
            $instance,
            $issued->token,
        ];
    }

    /**
     * @return array{AppInstance, string}
     */
    private function additionalInstance(
        Tenant $tenant,
    ): array {
        $instance = AppInstance::query()->create([
            'tenant_id' => $tenant->id,
            'channel' => 'web',
            'is_active' => true,
        ]);

        $issued = app(
            AppInstanceCredentialService::class
        )->issue($instance);

        return [
            $instance,
            $issued->token,
        ];
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
     * @return array{Branch, InventoryLocation, Product, Sku}
     */
    private function inventory(
        Tenant $tenant,
        string $branchCode,
        string $skuCode,
        int $stock = 20,
        int $priceMinor = 2575,
    ): array {
        return $this->inTenant(
            $tenant,
            function () use (
                $branchCode,
                $skuCode,
                $stock,
                $priceMinor,
            ): array {
                $branch = Branch::query()->create([
                    'code' => $branchCode,
                    'name_ar' => 'فرع '.$branchCode,
                    'name_en' => 'Branch '.$branchCode,
                    'is_active' => true,
                ]);

                $location =
                    InventoryLocation::query()
                        ->create([
                            'branch_id' => $branch->id,

                            'code' => $branchCode.'-STOCK',

                            'name_ar' => 'مخزون '.$branchCode,

                            'name_en' => 'Stock '.$branchCode,

                            'type' => 'stock',
                            'is_active' => true,
                        ]);

                $product = Product::query()->create([
                    'name_ar' => 'منتج '.$skuCode,
                    'name_en' => 'Product '.$skuCode,
                    'description_ar' => 'وصف',
                    'description_en' => 'Description',
                    'image_url' => 'https://cdn.example.test/products/'.$skuCode.'.png',
                    'is_active' => true,
                ]);

                $sku = Sku::query()->create([
                    'product_id' => $product->id,
                    'code' => $skuCode,
                    'barcode' => '628'.str_pad(
                        preg_replace(
                            '/\D/',
                            '',
                            $skuCode,
                        ) ?: '1',
                        9,
                        '0',
                        STR_PAD_LEFT,
                    ),
                    'track_inventory' => true,
                    'is_active' => true,
                ]);

                app(
                    SkuPriceService::class
                )->schedule(
                    $sku,
                    amountMinor: $priceMinor,
                    taxRateBps: 1500,
                    taxInclusive: true,
                    effectiveFrom: now()->subMinute(),
                );

                app(
                    StockLedgerService::class
                )->post(
                    $sku,
                    $location,
                    $stock,
                    InventoryMovementType::RECEIPT,
                    'seed-'.$branchCode.'-'.$skuCode,
                );

                return [
                    $branch,
                    $location,
                    $product,
                    $sku,
                ];
            },
        );
    }

    /**
     * @return array{string, string}
     */
    private function createCart(
        string $appToken,
        Branch $branch,
        string $key = 'create-cart-1',
    ): array {
        $response = $this
            ->withHeaders([
                'X-App-Instance-Key' => $appToken,

                'X-Branch-Id' => (string) $branch->id,

                'Idempotency-Key' => $key,
            ])
            ->postJson(
                '/api/v1/storefront/carts'
            )
            ->assertCreated()
            ->assertJsonPath(
                'data.cart.status',
                'active',
            )
            ->assertJsonPath(
                'data.branch.id',
                (string) $branch->id,
            )
            ->assertJsonPath(
                'data.totals.total_minor',
                0,
            );

        return [
            (string)
                $response->json(
                    'data.cart.id'
                ),

            (string)
                $response->json(
                    'data.cart_token'
                ),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function cartHeaders(
        string $appToken,
        Branch $branch,
        string $cartToken,
    ): array {
        return [
            'X-App-Instance-Key' => $appToken,

            'X-Branch-Id' => (string) $branch->id,

            'X-Cart-Token' => $cartToken,
        ];
    }

    public function test_guest_cart_http_lifecycle_uses_trusted_pricing(): void
    {
        [
            $tenant,
            ,
            $appToken,
        ] = $this->storefront(
            'Store A'
        );

        [
            $branch,
            ,
            ,
            $sku,
        ] = $this->inventory(
            $tenant,
            'MAIN',
            'SKU-1001',
        );

        [
            $cartId,
            $cartToken,
        ] = $this->createCart(
            $appToken,
            $branch,
        );

        $headers =
            $this->cartHeaders(
                $appToken,
                $branch,
                $cartToken,
            );

        $added = $this
            ->withHeaders([
                ...$headers,
                'Idempotency-Key' => 'add-1001',
            ])
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/items',
                [
                    'sku_id' => $sku->id,
                    'quantity' => 2,
                ],
            )
            ->assertOk()
            ->assertJsonPath(
                'data.items.0.quantity',
                2,
            )
            ->assertJsonPath(
                'data.items.0.image_url',
                'https://cdn.example.test/products/SKU-1001.png',
            )
            ->assertJsonPath(
                'data.items.0.pricing.display_unit_amount_minor',
                2575,
            )
            ->assertJsonPath(
                'data.items.0.pricing.line_total_minor',
                5150,
            )
            ->assertJsonPath(
                'data.totals.total_minor',
                5150,
            );

        $itemId =
            (string)
            $added->json(
                'data.items.0.cart_item_id'
            );

        $this->withHeaders(
            $headers
        )
            ->patchJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/items/'.
                $itemId,
                [
                    'quantity' => 5,
                ],
            )
            ->assertOk()
            ->assertJsonPath(
                'data.items.0.quantity',
                5,
            )
            ->assertJsonPath(
                'data.totals.total_minor',
                12875,
            );

        $this->withHeaders(
            $headers
        )
            ->deleteJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/items/'.
                $itemId
            )
            ->assertOk()
            ->assertJsonCount(
                0,
                'data.items',
            )
            ->assertJsonPath(
                'data.totals.total_minor',
                0,
            );

        /*
         * DELETE is idempotent: retrying after a
         * lost successful response stays successful.
         */
        $this->withHeaders(
            $headers
        )
            ->deleteJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/items/'.
                $itemId
            )
            ->assertOk()
            ->assertJsonCount(
                0,
                'data.items',
            );

        $this->withHeaders(
            $headers
        )
            ->getJson(
                '/api/v1/storefront/carts/'.
                $cartId
            )
            ->assertOk()
            ->assertJsonCount(
                0,
                'data.items',
            );
    }

    public function test_cart_creation_and_add_item_are_idempotent(): void
    {
        [
            $tenant,
            ,
            $appToken,
        ] = $this->storefront(
            'Store A'
        );

        [
            $branch,
            $location,
            ,
            $sku,
        ] = $this->inventory(
            $tenant,
            'MAIN',
            'SKU-2001',
            stock: 10,
        );

        $createHeaders = [
            'X-App-Instance-Key' => $appToken,

            'X-Branch-Id' => (string) $branch->id,

            'Idempotency-Key' => 'create-replay',
        ];

        $firstCreate = $this
            ->withHeaders(
                $createHeaders
            )
            ->postJson(
                '/api/v1/storefront/carts'
            )
            ->assertCreated();

        $secondCreate = $this
            ->withHeaders(
                $createHeaders
            )
            ->postJson(
                '/api/v1/storefront/carts'
            )
            ->assertCreated();

        $this->assertSame(
            $firstCreate->json(
                'data.cart.id'
            ),
            $secondCreate->json(
                'data.cart.id'
            ),
        );

        $this->assertSame(
            $firstCreate->json(
                'data.cart_token'
            ),
            $secondCreate->json(
                'data.cart_token'
            ),
        );

        $cartId =
            (string)
            $firstCreate->json(
                'data.cart.id'
            );

        $cartToken =
            (string)
            $firstCreate->json(
                'data.cart_token'
            );

        $headers = [
            ...$this->cartHeaders(
                $appToken,
                $branch,
                $cartToken,
            ),
            'Idempotency-Key' => 'add-replay',
        ];

        $this->withHeaders(
            $headers
        )
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/items',
                [
                    'sku_id' => $sku->id,
                    'quantity' => 2,
                ],
            )
            ->assertOk()
            ->assertJsonPath(
                'data.items.0.quantity',
                2,
            );

        /*
         * Consume remaining ATS elsewhere. A replay
         * must still reach CartService's receipt
         * before storefront stock prechecks.
         */
        $this->inTenant(
            $tenant,
            function () use (
                $sku,
                $location,
            ): void {
                app(
                    InventoryReservationService::class
                )->reserve(
                    $sku,
                    $location,
                    10,
                    'external-hold',
                    expiresAt: now()->addHour(),
                );
            },
        );

        $this->withHeaders(
            $headers
        )
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/items',
                [
                    'sku_id' => $sku->id,
                    'quantity' => 2,
                ],
            )
            ->assertOk()
            ->assertJsonPath(
                'data.items.0.quantity',
                2,
            );

        /*
         * Same key, changed payload must be a
         * conflict even though current ATS is zero.
         */
        $this->withHeaders(
            $headers
        )
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/items',
                [
                    'sku_id' => $sku->id,
                    'quantity' => 3,
                ],
            )
            ->assertConflict()
            ->assertJsonPath(
                'error.code',
                'IDEMPOTENCY_CONFLICT',
            );
    }

    public function test_cart_access_is_hidden_from_wrong_token_and_app_instance(): void
    {
        [
            $tenant,
            ,
            $appToken,
        ] = $this->storefront(
            'Store A'
        );

        [
            $branch,
        ] = $this->inventory(
            $tenant,
            'MAIN',
            'SKU-3001',
        );

        [
            ,
            $sameTenantOtherToken,
        ] = $this->additionalInstance(
            $tenant
        );

        [
            $cartId,
            $cartToken,
        ] = $this->createCart(
            $appToken,
            $branch,
        );

        $this->withHeaders([
            'X-App-Instance-Key' => $appToken,

            'X-Branch-Id' => (string) $branch->id,

            'X-Cart-Token' => 'wrong-token',
        ])
            ->getJson(
                '/api/v1/storefront/carts/'.
                $cartId
            )
            ->assertNotFound()
            ->assertJsonPath(
                'error.code',
                'CART_NOT_FOUND',
            );

        $this->withHeaders([
            'X-App-Instance-Key' => $sameTenantOtherToken,

            'X-Branch-Id' => (string) $branch->id,

            'X-Cart-Token' => $cartToken,
        ])
            ->getJson(
                '/api/v1/storefront/carts/'.
                $cartId
            )
            ->assertNotFound()
            ->assertJsonPath(
                'error.code',
                'CART_NOT_FOUND',
            );

        [
            $foreignTenant,
            ,
            $foreignToken,
        ] = $this->storefront(
            'Store B'
        );

        [
            $foreignBranch,
        ] = $this->inventory(
            $foreignTenant,
            'FOREIGN',
            'SKU-3002',
        );

        $this->withHeaders([
            'X-App-Instance-Key' => $foreignToken,

            'X-Branch-Id' => (string)
                $foreignBranch->id,

            'X-Cart-Token' => $cartToken,
        ])
            ->getJson(
                '/api/v1/storefront/carts/'.
                $cartId
            )
            ->assertNotFound()
            ->assertJsonPath(
                'error.code',
                'CART_NOT_FOUND',
            );
    }

    public function test_storefront_cart_prevents_cross_branch_mixing(): void
    {
        [
            $tenant,
            ,
            $appToken,
        ] = $this->storefront(
            'Store A'
        );

        [
            $branchA,
            ,
            ,
            $skuA,
        ] = $this->inventory(
            $tenant,
            'A',
            'SKU-4001',
        );

        [
            $branchB,
        ] = $this->inventory(
            $tenant,
            'B',
            'SKU-4002',
        );

        [
            $cartId,
            $cartToken,
        ] = $this->createCart(
            $appToken,
            $branchA,
        );

        $this->withHeaders([
            ...$this->cartHeaders(
                $appToken,
                $branchA,
                $cartToken,
            ),
            'Idempotency-Key' => 'add-a',
        ])
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/items',
                [
                    'sku_id' => $skuA->id,
                    'quantity' => 1,
                ],
            )
            ->assertOk();

        $this->withHeaders(
            $this->cartHeaders(
                $appToken,
                $branchB,
                $cartToken,
            )
        )
            ->getJson(
                '/api/v1/storefront/carts/'.
                $cartId
            )
            ->assertConflict()
            ->assertJsonPath(
                'error.code',
                'CART_BRANCH_MISMATCH',
            );
    }

    public function test_stock_and_tenant_boundaries_are_enforced(): void
    {
        [
            $tenantA,
            ,
            $appTokenA,
        ] = $this->storefront(
            'Store A'
        );

        [
            $branchA,
            ,
            ,
            $skuA,
        ] = $this->inventory(
            $tenantA,
            'A',
            'SKU-5001',
            stock: 2,
        );

        [
            $tenantB,
        ] = $this->storefront(
            'Store B'
        );

        [
            ,
            ,
            ,
            $foreignSku,
        ] = $this->inventory(
            $tenantB,
            'B',
            'SKU-5002',
        );

        [
            $cartId,
            $cartToken,
        ] = $this->createCart(
            $appTokenA,
            $branchA,
        );

        $headers =
            $this->cartHeaders(
                $appTokenA,
                $branchA,
                $cartToken,
            );

        $this->withHeaders([
            ...$headers,
            'Idempotency-Key' => 'too-many',
        ])
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/items',
                [
                    'sku_id' => $skuA->id,
                    'quantity' => 3,
                ],
            )
            ->assertConflict()
            ->assertJsonPath(
                'error.code',
                'INSUFFICIENT_STOCK',
            );

        $this->withHeaders([
            ...$headers,
            'Idempotency-Key' => 'foreign-sku',
        ])
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/items',
                [
                    'sku_id' => $foreignSku->id,

                    'quantity' => 1,
                ],
            )
            ->assertNotFound()
            ->assertJsonPath(
                'error.code',
                'SKU_NOT_FOUND',
            );
    }

    public function test_checkout_quote_uses_trusted_server_money_and_reserves_inventory(): void
    {
        [
            $tenant,
            ,
            $appToken,
        ] = $this->storefront(
            'Store Checkout'
        );

        [
            $branch,
            ,
            ,
            $sku,
        ] = $this->inventory(
            $tenant,
            'MAIN',
            'SKU-CHECKOUT-1',
            stock: 10,
            priceMinor: 2575,
        );

        [
            $cartId,
            $cartToken,
        ] = $this->createCart(
            $appToken,
            $branch,
        );

        $headers =
            $this->cartHeaders(
                $appToken,
                $branch,
                $cartToken,
            );

        $this->withHeaders([
            ...$headers,
            'Idempotency-Key' => 'checkout-add-1',
        ])
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/items',
                [
                    'sku_id' => $sku->id,
                    'quantity' => 2,
                ],
            )
            ->assertOk();

        /*
         * Client money is deliberately forged. The endpoint
         * must ignore it and return the trusted server quote.
         */
        $quoted = $this
            ->withHeaders(
                $headers
            )
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/checkout/quote',
                [
                    'currency_code' => 'USD',
                    'total_minor' => 1,
                    'tax_minor' => 0,
                ],
            )
            ->assertOk()
            ->assertJsonPath(
                'data.cart_id',
                $cartId,
            )
            ->assertJsonPath(
                'data.currency_code',
                'SAR',
            )
            ->assertJsonPath(
                'data.quote.total_minor',
                5150,
            )
            ->assertJsonPath(
                'data.quote.shipping_minor',
                0,
            )
            ->assertJsonPath(
                'data.quote.lines.0.quantity',
                2,
            );

        $this->assertNotNull(
            $quoted->json(
                'data.inventory_reserved_until'
            )
        );

        $this->assertSame(
            $quoted->json(
                'data.quote.expires_at'
            ),
            $quoted->json(
                'data.inventory_reserved_until'
            ),
        );

        $this->inTenant(
            $tenant,
            function () use ($cartId): void {
                $cart = Cart::query()
                    ->where(
                        'public_id',
                        $cartId,
                    )
                    ->firstOrFail();

                $this->assertNotNull(
                    $cart->inventory_reserved_until
                );

                $this->assertTrue(
                    $cart
                        ->inventory_reserved_until
                        ->isFuture()
                );
            },
        );
    }

    public function test_checkout_quote_fails_closed_for_empty_cart_without_reservation(): void
    {
        [
            $tenant,
            ,
            $appToken,
        ] = $this->storefront(
            'Store Empty Checkout'
        );

        [$branch] = $this->inventory(
            $tenant,
            'MAIN',
            'SKU-CHECKOUT-EMPTY',
        );

        [
            $cartId,
            $cartToken,
        ] = $this->createCart(
            $appToken,
            $branch,
        );

        $this->withHeaders(
            $this->cartHeaders(
                $appToken,
                $branch,
                $cartToken,
            )
        )
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/checkout/quote'
            )
            ->assertConflict()
            ->assertJsonPath(
                'error.code',
                'CHECKOUT_REVIEW_REQUIRED',
            );

        $this->inTenant(
            $tenant,
            function () use ($cartId): void {
                $cart = Cart::query()
                    ->where(
                        'public_id',
                        $cartId,
                    )
                    ->firstOrFail();

                $this->assertNull(
                    $cart->inventory_reserved_until
                );
            },
        );
    }

    public function test_checkout_quote_hides_cart_from_wrong_guest_token(): void
    {
        [
            $tenant,
            ,
            $appToken,
        ] = $this->storefront(
            'Store Hidden Checkout'
        );

        [
            $branch,
        ] = $this->inventory(
            $tenant,
            'MAIN',
            'SKU-CHECKOUT-HIDDEN',
        );

        [
            $cartId,
        ] = $this->createCart(
            $appToken,
            $branch,
        );

        $this->withHeaders([
            'X-App-Instance-Key' => $appToken,
            'X-Branch-Id' => (string) $branch->id,
            'X-Cart-Token' => str_repeat('x', 64),
        ])
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/checkout/quote'
            )
            ->assertNotFound()
            ->assertJsonPath(
                'error.code',
                'CART_NOT_FOUND',
            );
    }

    public function test_checkout_order_reprices_server_side_and_replays_same_order(): void
    {
        [
            $tenant,
            ,
            $appToken,
        ] = $this->storefront(
            'Store Order Checkout'
        );

        [
            $branch,
            ,
            ,
            $sku,
        ] = $this->inventory(
            $tenant,
            'MAIN',
            'SKU-ORDER-1',
            stock: 10,
            priceMinor: 2575,
        );

        [
            $cartId,
            $cartToken,
        ] = $this->createCart(
            $appToken,
            $branch,
        );

        $headers =
            $this->cartHeaders(
                $appToken,
                $branch,
                $cartToken,
            );

        $this->withHeaders([
            ...$headers,
            'Idempotency-Key' => 'order-add-1',
        ])
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/items',
                [
                    'sku_id' => $sku->id,
                    'quantity' => 2,
                ],
            )
            ->assertOk();

        $this->withHeaders(
            $headers
        )
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/checkout/quote'
            )
            ->assertOk();

        $first = $this
            ->withHeaders(
                $headers
            )
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/checkout/order',
                [
                    'customer_name' => 'Test Customer',
                    'customer_phone' => '+966500000000',
                    'customer_email' => 'test@example.com',
                    'shipping_address' => [
                        'city' => 'Jeddah',
                        'line1' => 'Sandbox Street',
                    ],

                    /*
                     * These client values are intentionally
                     * forged and must be ignored completely.
                     */
                    'currency_code' => 'USD',
                    'total_minor' => 1,
                ],
            )
            ->assertCreated()
            ->assertJsonPath(
                'data.order.status',
                'pending',
            )
            ->assertJsonPath(
                'data.order.currency_code',
                'SAR',
            )
            ->assertJsonPath(
                'data.order.total_minor',
                5150,
            )
            ->assertJsonPath(
                'data.order.customer_name',
                'Test Customer',
            )
            ->assertJsonPath(
                'data.order.shipping_address.city',
                'Jeddah',
            )
            ->assertJsonPath(
                'data.order.items.0.quantity',
                2,
            );

        $orderId =
            (string) $first->json(
                'data.order.id'
            );

        /*
         * Replaying conversion with changed customer data
         * must return the persisted first Order snapshot.
         */
        $second = $this
            ->withHeaders(
                $headers
            )
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/checkout/order',
                [
                    'customer_name' => 'Changed Name',
                ],
            )
            ->assertCreated()
            ->assertJsonPath(
                'data.order.id',
                $orderId,
            )
            ->assertJsonPath(
                'data.order.customer_name',
                'Test Customer',
            )
            ->assertJsonPath(
                'data.order.total_minor',
                5150,
            );

        $this->assertSame(
            $orderId,
            $second->json(
                'data.order.id'
            ),
        );

        $this->inTenant(
            $tenant,
            function () use (
                $cartId,
                $orderId,
            ): void {
                $this->assertSame(
                    1,
                    Order::query()
                        ->where(
                            'public_id',
                            $orderId,
                        )
                        ->count(),
                );

                $cart = Cart::query()
                    ->where(
                        'public_id',
                        $cartId,
                    )
                    ->firstOrFail();

                $this->assertSame(
                    'converted',
                    $cart->status,
                );

                $this->assertNull(
                    $cart
                        ->inventory_reserved_until
                );
            },
        );

        $this
            ->withHeaders(
                $headers
            )
            ->getJson(
                '/api/v1/storefront/carts/'.
                $cartId
            )
            ->assertConflict()
            ->assertJsonPath(
                'error.code',
                'CART_NOT_MUTABLE',
            );
    }

    public function test_checkout_order_requires_active_checkout_reservation(): void
    {
        [
            $tenant,
            ,
            $appToken,
        ] = $this->storefront(
            'Store Order Without Quote'
        );

        [
            $branch,
            ,
            ,
            $sku,
        ] = $this->inventory(
            $tenant,
            'MAIN',
            'SKU-ORDER-NO-QUOTE',
            stock: 10,
        );

        [
            $cartId,
            $cartToken,
        ] = $this->createCart(
            $appToken,
            $branch,
        );

        $headers =
            $this->cartHeaders(
                $appToken,
                $branch,
                $cartToken,
            );

        $this->withHeaders([
            ...$headers,
            'Idempotency-Key' => 'no-quote-add',
        ])
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/items',
                [
                    'sku_id' => $sku->id,
                    'quantity' => 1,
                ],
            )
            ->assertOk();

        $this->withHeaders(
            $headers
        )
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/checkout/order',
                [
                    'customer_name' => 'No Quote',
                ],
            )
            ->assertConflict()
            ->assertJsonPath(
                'error.code',
                'CHECKOUT_REVIEW_REQUIRED',
            );

        $this->inTenant(
            $tenant,
            function (): void {
                $this->assertSame(
                    0,
                    Order::query()->count(),
                );
            },
        );
    }

    public function test_payment_attempt_uses_authoritative_order_money_and_replays(): void
    {
        [
            $tenant,
            ,
            $appToken,
        ] = $this->storefront(
            'Store Payment Checkout'
        );

        [
            $branch,
            ,
            ,
            $sku,
        ] = $this->inventory(
            $tenant,
            'MAIN',
            'SKU-PAYMENT-1',
            stock: 10,
            priceMinor: 2575,
        );

        [
            $cartId,
            $cartToken,
        ] = $this->createCart(
            $appToken,
            $branch,
        );

        $headers =
            $this->cartHeaders(
                $appToken,
                $branch,
                $cartToken,
            );

        $this->withHeaders([
            ...$headers,
            'Idempotency-Key' => 'payment-add-1',
        ])
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/items',
                [
                    'sku_id' => $sku->id,
                    'quantity' => 2,
                ],
            )
            ->assertOk();

        $this->withHeaders(
            $headers
        )
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/checkout/quote'
            )
            ->assertOk();

        $this->withHeaders(
            $headers
        )
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/checkout/order',
                [
                    'customer_name' => 'Sandbox Buyer',
                ],
            )
            ->assertCreated();

        $attemptHeaders = [
            ...$headers,
            'Idempotency-Key' => 'sandbox-attempt-1',
        ];

        $first = $this
            ->withHeaders(
                $attemptHeaders
            )
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/checkout/payment-attempts',
                [
                    'provider_code' => 'sandbox',
                    'method_code' => 'card',

                    /*
                     * Client financial evidence is forged
                     * deliberately. It must not participate
                     * in Payment or Attempt materialization.
                     */
                    'currency_code' => 'USD',
                    'amount_minor' => 1,
                ],
            )
            ->assertCreated()
            ->assertJsonPath(
                'data.payment.status',
                'pending',
            )
            ->assertJsonPath(
                'data.payment.currency_code',
                'SAR',
            )
            ->assertJsonPath(
                'data.payment.amount_minor',
                5150,
            )
            ->assertJsonPath(
                'data.attempt.status',
                'created',
            )
            ->assertJsonPath(
                'data.attempt.provider_code',
                'sandbox',
            )
            ->assertJsonPath(
                'data.attempt.method_code',
                'card',
            )
            ->assertJsonPath(
                'data.attempt.currency_code',
                'SAR',
            )
            ->assertJsonPath(
                'data.attempt.amount_minor',
                5150,
            );

        $attemptId =
            (string) $first->json(
                'data.attempt.id'
            );

        $paymentId =
            (string) $first->json(
                'data.payment.id'
            );

        $this->withHeaders(
            $attemptHeaders
        )
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/checkout/payment-attempts',
                [
                    'provider_code' => 'SANDBOX',
                    'method_code' => 'CARD',
                ],
            )
            ->assertCreated()
            ->assertJsonPath(
                'data.payment.id',
                $paymentId,
            )
            ->assertJsonPath(
                'data.attempt.id',
                $attemptId,
            );

        $this->inTenant(
            $tenant,
            function () use (
                $paymentId,
                $attemptId,
            ): void {
                $this->assertSame(
                    1,
                    Payment::query()
                        ->where(
                            'public_id',
                            $paymentId,
                        )
                        ->count(),
                );

                $this->assertSame(
                    1,
                    PaymentAttempt::query()
                        ->where(
                            'public_id',
                            $attemptId,
                        )
                        ->count(),
                );
            },
        );
    }

    public function test_payment_attempt_rejects_idempotency_semantic_change(): void
    {
        [
            $tenant,
            ,
            $appToken,
        ] = $this->storefront(
            'Store Payment Conflict'
        );

        [
            $branch,
            ,
            ,
            $sku,
        ] = $this->inventory(
            $tenant,
            'MAIN',
            'SKU-PAYMENT-CONFLICT',
            stock: 10,
        );

        [
            $cartId,
            $cartToken,
        ] = $this->createCart(
            $appToken,
            $branch,
        );

        $headers =
            $this->cartHeaders(
                $appToken,
                $branch,
                $cartToken,
            );

        $this->withHeaders([
            ...$headers,
            'Idempotency-Key' => 'payment-conflict-add',
        ])
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/items',
                [
                    'sku_id' => $sku->id,
                    'quantity' => 1,
                ],
            )
            ->assertOk();

        $this->withHeaders($headers)
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/checkout/quote'
            )
            ->assertOk();

        $this->withHeaders($headers)
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/checkout/order'
            )
            ->assertCreated();

        $attemptHeaders = [
            ...$headers,
            'Idempotency-Key' => 'same-payment-key',
        ];

        $this->withHeaders(
            $attemptHeaders
        )
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/checkout/payment-attempts',
                [
                    'provider_code' => 'sandbox',
                    'method_code' => 'card',
                ],
            )
            ->assertCreated();

        $this->withHeaders(
            $attemptHeaders
        )
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/checkout/payment-attempts',
                [
                    'provider_code' => 'sandbox',
                    'method_code' => 'mada',
                ],
            )
            ->assertConflict()
            ->assertJsonPath(
                'error.code',
                'PAYMENT_IDEMPOTENCY_CONFLICT',
            );

        $this->inTenant(
            $tenant,
            function (): void {
                $this->assertSame(
                    1,
                    PaymentAttempt::query()
                        ->count(),
                );
            },
        );
    }

    public function test_payment_attempt_requires_checkout_order_and_guest_credential(): void
    {
        [
            $tenant,
            ,
            $appToken,
        ] = $this->storefront(
            'Store Payment Guard'
        );

        [$branch] = $this->inventory(
            $tenant,
            'MAIN',
            'SKU-PAYMENT-GUARD',
        );

        [
            $cartId,
            $cartToken,
        ] = $this->createCart(
            $appToken,
            $branch,
        );

        $this->withHeaders([
            ...$this->cartHeaders(
                $appToken,
                $branch,
                $cartToken,
            ),
            'Idempotency-Key' => 'no-order-payment',
        ])
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/checkout/payment-attempts',
                [
                    'provider_code' => 'sandbox',
                    'method_code' => 'card',
                ],
            )
            ->assertConflict()
            ->assertJsonPath(
                'error.code',
                'PAYMENT_NOT_AVAILABLE',
            );

        $this->withHeaders([
            'X-App-Instance-Key' => $appToken,
            'X-Branch-Id' => (string) $branch->id,
            'X-Cart-Token' => str_repeat('x', 64),
            'Idempotency-Key' => 'wrong-token-payment',
        ])
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/checkout/payment-attempts',
                [
                    'provider_code' => 'sandbox',
                    'method_code' => 'card',
                ],
            )
            ->assertNotFound()
            ->assertJsonPath(
                'error.code',
                'CART_NOT_FOUND',
            );
    }

    public function test_sandbox_payment_success_confirms_order_and_replays(): void
    {
        [
            $tenant,
            ,
            $appToken,
        ] = $this->storefront(
            'Store Sandbox Success'
        );

        [
            $branch,
            ,
            ,
            $sku,
        ] = $this->inventory(
            $tenant,
            'MAIN',
            'SKU-SANDBOX-SUCCESS',
            stock: 10,
            priceMinor: 2575,
        );

        [
            $cartId,
            $cartToken,
        ] = $this->createCart(
            $appToken,
            $branch,
        );

        $headers =
            $this->cartHeaders(
                $appToken,
                $branch,
                $cartToken,
            );

        $this->withHeaders([
            ...$headers,
            'Idempotency-Key' => 'sandbox-success-add',
        ])
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/items',
                [
                    'sku_id' => $sku->id,
                    'quantity' => 2,
                ],
            )
            ->assertOk();

        $this->withHeaders($headers)
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/checkout/quote'
            )
            ->assertOk();

        $order = $this->withHeaders($headers)
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/checkout/order'
            )
            ->assertCreated()
            ->assertJsonPath(
                'data.order.status',
                'pending',
            )
            ->assertJsonPath(
                'data.order.total_minor',
                5150,
            );

        $attempt = $this->withHeaders([
            ...$headers,
            'Idempotency-Key' => 'sandbox-success-attempt',
        ])
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/checkout/payment-attempts',
                [
                    'provider_code' => 'sandbox',
                    'method_code' => 'card',
                ],
            )
            ->assertCreated();

        $attemptId =
            (string) $attempt->json(
                'data.attempt.id'
            );

        $settleUrl =
            '/api/v1/storefront/carts/'.
            $cartId.
            '/checkout/payment-attempts/'.
            $attemptId.
            '/sandbox/settle';

        $first = $this->withHeaders($headers)
            ->postJson(
                $settleUrl,
                ['scenario' => 'success'],
            )
            ->assertOk()
            ->assertJsonPath(
                'data.scenario',
                'success',
            )
            ->assertJsonPath(
                'data.order.status',
                'confirmed',
            )
            ->assertJsonPath(
                'data.payment.status',
                'paid',
            )
            ->assertJsonPath(
                'data.attempt.status',
                'succeeded',
            )
            ->assertJsonPath(
                'data.payment.amount_minor',
                5150,
            );

        $this->assertSame(
            $order->json('data.order.id'),
            $first->json('data.order.id'),
        );

        /*
         * Replay the same sandbox provider event. The payment
         * aggregate must remain exactly settled once.
         */
        $this->withHeaders($headers)
            ->postJson(
                $settleUrl,
                ['scenario' => 'success'],
            )
            ->assertOk()
            ->assertJsonPath(
                'data.order.status',
                'confirmed',
            )
            ->assertJsonPath(
                'data.payment.status',
                'paid',
            )
            ->assertJsonPath(
                'data.attempt.status',
                'succeeded',
            );

        $this->inTenant(
            $tenant,
            function (): void {
                $this->assertSame(
                    1,
                    Payment::query()->count(),
                );

                $this->assertSame(
                    1,
                    PaymentAttempt::query()->count(),
                );
            },
        );
    }

    public function test_sandbox_decline_allows_fresh_attempt_then_success(): void
    {
        [
            $tenant,
            ,
            $appToken,
        ] = $this->storefront(
            'Store Sandbox Retry'
        );

        [
            $branch,
            ,
            ,
            $sku,
        ] = $this->inventory(
            $tenant,
            'MAIN',
            'SKU-SANDBOX-RETRY',
            stock: 10,
            priceMinor: 4500,
        );

        [
            $cartId,
            $cartToken,
        ] = $this->createCart(
            $appToken,
            $branch,
        );

        $headers =
            $this->cartHeaders(
                $appToken,
                $branch,
                $cartToken,
            );

        $this->withHeaders([
            ...$headers,
            'Idempotency-Key' => 'sandbox-retry-add',
        ])
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/items',
                [
                    'sku_id' => $sku->id,
                    'quantity' => 1,
                ],
            )
            ->assertOk();

        $this->withHeaders($headers)
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/checkout/quote'
            )
            ->assertOk();

        $this->withHeaders($headers)
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/checkout/order'
            )
            ->assertCreated();

        $declined = $this->withHeaders([
            ...$headers,
            'Idempotency-Key' => 'sandbox-decline-attempt',
        ])
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/checkout/payment-attempts',
                [
                    'provider_code' => 'sandbox',
                    'method_code' => 'card',
                ],
            )
            ->assertCreated();

        $declinedId =
            (string) $declined->json(
                'data.attempt.id'
            );

        $this->withHeaders($headers)
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/checkout/payment-attempts/'.
                $declinedId.
                '/sandbox/settle',
                ['scenario' => 'decline'],
            )
            ->assertOk()
            ->assertJsonPath(
                'data.order.status',
                'pending',
            )
            ->assertJsonPath(
                'data.payment.status',
                'pending',
            )
            ->assertJsonPath(
                'data.attempt.status',
                'failed',
            );

        $retry = $this->withHeaders([
            ...$headers,
            'Idempotency-Key' => 'sandbox-retry-attempt',
        ])
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/checkout/payment-attempts',
                [
                    'provider_code' => 'sandbox',
                    'method_code' => 'mada',
                ],
            )
            ->assertCreated()
            ->assertJsonPath(
                'data.attempt.status',
                'created',
            );

        $retryId =
            (string) $retry->json(
                'data.attempt.id'
            );

        $this->withHeaders($headers)
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/checkout/payment-attempts/'.
                $retryId.
                '/sandbox/settle',
                ['scenario' => 'success'],
            )
            ->assertOk()
            ->assertJsonPath(
                'data.order.status',
                'confirmed',
            )
            ->assertJsonPath(
                'data.payment.status',
                'paid',
            )
            ->assertJsonPath(
                'data.attempt.status',
                'succeeded',
            );

        $this->inTenant(
            $tenant,
            function (): void {
                $this->assertSame(
                    2,
                    PaymentAttempt::query()->count(),
                );

                $this->assertSame(
                    1,
                    PaymentAttempt::query()
                        ->where(
                            'status',
                            'failed',
                        )
                        ->count(),
                );

                $this->assertSame(
                    1,
                    PaymentAttempt::query()
                        ->where(
                            'status',
                            'succeeded',
                        )
                        ->count(),
                );
            },
        );
    }

    public function test_cart_request_validation_and_missing_item_are_safe(): void
    {
        [
            $tenant,
            ,
            $appToken,
        ] = $this->storefront(
            'Store A'
        );

        [
            $branch,
        ] = $this->inventory(
            $tenant,
            'MAIN',
            'SKU-6001',
        );

        $this->withHeaders([
            'X-App-Instance-Key' => $appToken,

            'X-Branch-Id' => (string) $branch->id,
        ])
            ->postJson(
                '/api/v1/storefront/carts'
            )
            ->assertBadRequest()
            ->assertJsonPath(
                'error.code',
                'IDEMPOTENCY_KEY_REQUIRED',
            );

        [
            $cartId,
            $cartToken,
        ] = $this->createCart(
            $appToken,
            $branch,
        );

        $headers =
            $this->cartHeaders(
                $appToken,
                $branch,
                $cartToken,
            );

        $this->withHeaders(
            $headers
        )
            ->patchJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/items/'.
                '00000000-0000-4000-8000-000000000000',
                [
                    'quantity' => 1,
                ],
            )
            ->assertNotFound()
            ->assertJsonPath(
                'error.code',
                'CART_ITEM_NOT_FOUND',
            );

        $this->withHeaders([
            ...$headers,
            'Idempotency-Key' => 'oversized-line',
        ])
            ->postJson(
                '/api/v1/storefront/carts/'.
                $cartId.
                '/items',
                [
                    'sku_id' => 1,
                    'quantity' => 1000,
                ],
            )
            ->assertStatus(422)
            ->assertJsonPath(
                'error.code',
                'INVALID_CART_REQUEST',
            );
    }
}
