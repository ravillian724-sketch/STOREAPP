<?php

namespace Tests\Feature;

use App\Models\AppInstance;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sku;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AppInstanceCredentialService;
use App\Services\TenantRbacProvisioner;
use App\Support\Authorization\SystemRoleCatalog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogAdminApiTest extends TestCase
{
    use RefreshDatabase;

    private function inTenant(
        Tenant $tenant,
        callable $callback,
    ): mixed {
        $context = app(
            TenantContext::class
        );

        $previous = $context->id();

        $context->set($tenant->id);

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

    private function store(
        string $name,
    ): array {
        $tenant = Tenant::query()->create([
            'name_ar' => $name,
            'name_en' => $name,
            'country_code' => 'SA',
            'currency_code' => 'SAR',
            'vat_rate' => 15,
            'primary_color' => '#000000',
            'secondary_color' => '#FFFFFF',
            'is_active' => true,
        ]);

        app(TenantRbacProvisioner::class)
            ->provision($tenant);

        $instance =
            AppInstance::query()->create([
                'tenant_id' => $tenant->id,

                'channel' => 'mobile',

                'is_active' => true,
            ]);

        $issued = app(
            AppInstanceCredentialService::class
        )->issue($instance);

        return [
            'tenant' => $tenant,

            'instance_token' => $issued->token,
        ];
    }

    private function staff(
        Tenant $tenant,
        string $email,
        string $roleCode,
    ): User {
        return $this->inTenant(
            $tenant,
            function () use (
                $email,
                $roleCode,
            ): User {
                $user =
                    User::query()->create([
                        'name' => 'Staff',
                        'email' => $email,
                        'password' => 'Secret123!',
                        'is_active' => true,
                    ]);

                $role = Role::query()
                    ->where(
                        'code',
                        $roleCode,
                    )
                    ->firstOrFail();

                $user->assignRole($role);

                return $user;
            },
        );
    }

    private function login(
        array $store,
        string $email,
    ): string {
        $response = $this
            ->withHeader(
                'X-App-Instance-Key',
                $store['instance_token'],
            )
            ->postJson(
                '/api/v1/staff/auth/login',
                [
                    'email' => $email,

                    'password' => 'Secret123!',

                    'device_name' => 'phpunit',
                ],
            );

        $response->assertOk();

        return (string) $response->json(
            'data.access_token'
        );
    }

    private function headers(
        array $store,
        string $token,
    ): array {
        return [
            'X-App-Instance-Key' => $store['instance_token'],

            'Authorization' => 'Bearer '.$token,
        ];
    }

    private function product(
        Tenant $tenant,
        string $name,
    ): Product {
        return $this->inTenant(
            $tenant,
            fn (): Product => Product::query()->create([
                'name_ar' => $name,
                'name_en' => $name,
                'is_active' => true,
            ]),
        );
    }

    public function test_manager_can_create_product_and_sku(): void
    {
        $store = $this->store(
            'Tenant A'
        );

        $this->staff(
            $store['tenant'],
            'manager@example.com',
            SystemRoleCatalog::MANAGER,
        );

        $token = $this->login(
            $store,
            'manager@example.com',
        );

        $headers = $this->headers(
            $store,
            $token,
        );

        $productResponse = $this
            ->withHeaders($headers)
            ->postJson(
                '/api/v1/admin/catalog/products',
                [
                    'name_ar' => 'منتج أ',

                    'name_en' => 'Product A',
                ],
            );

        $productResponse
            ->assertCreated()
            ->assertJsonPath(
                'data.product.name_en',
                'Product A',
            );

        $productId =
            $productResponse->json(
                'data.product.id'
            );

        $this
            ->withHeaders($headers)
            ->postJson(
                '/api/v1/admin/catalog/products/'.
                $productId.
                '/skus',
                [
                    'code' => 'sku-001',

                    'barcode' => '628000000001',
                ],
            )
            ->assertCreated()
            ->assertJsonPath(
                'data.sku.code',
                'SKU-001',
            );

        $this
            ->withHeaders($headers)
            ->getJson(
                '/api/v1/admin/catalog/products'
            )
            ->assertOk()
            ->assertJsonFragment([
                'code' => 'SKU-001',
            ]);
    }

    public function test_view_only_staff_cannot_manage_catalog(): void
    {
        $store = $this->store(
            'Tenant A'
        );

        $this->staff(
            $store['tenant'],
            'cashier@example.com',
            SystemRoleCatalog::CASHIER,
        );

        $this->product(
            $store['tenant'],
            'Existing Product',
        );

        $token = $this->login(
            $store,
            'cashier@example.com',
        );

        $headers = $this->headers(
            $store,
            $token,
        );

        $this
            ->withHeaders($headers)
            ->getJson(
                '/api/v1/admin/catalog/products'
            )
            ->assertOk();

        $this
            ->withHeaders($headers)
            ->postJson(
                '/api/v1/admin/catalog/products',
                [
                    'name_ar' => 'Blocked',

                    'name_en' => 'Blocked',
                ],
            )
            ->assertForbidden()
            ->assertJsonPath(
                'error.code',
                'FORBIDDEN',
            );
    }

    public function test_catalog_listing_does_not_cross_tenants(): void
    {
        $storeA = $this->store(
            'Tenant A'
        );

        $storeB = $this->store(
            'Tenant B'
        );

        $this->staff(
            $storeA['tenant'],
            'owner@example.com',
            SystemRoleCatalog::OWNER,
        );

        $this->product(
            $storeA['tenant'],
            'Visible Product',
        );

        $this->product(
            $storeB['tenant'],
            'Foreign Product',
        );

        $token = $this->login(
            $storeA,
            'owner@example.com',
        );

        $this
            ->withHeaders(
                $this->headers(
                    $storeA,
                    $token,
                )
            )
            ->getJson(
                '/api/v1/admin/catalog/products'
            )
            ->assertOk()
            ->assertJsonFragment([
                'name_en' => 'Visible Product',
            ])
            ->assertJsonMissing([
                'name_en' => 'Foreign Product',
            ]);
    }

    public function test_sku_code_is_unique_case_insensitively_after_normalization(): void
    {
        $store = $this->store(
            'Tenant A'
        );

        $this->staff(
            $store['tenant'],
            'manager@example.com',
            SystemRoleCatalog::MANAGER,
        );

        $product = $this->product(
            $store['tenant'],
            'Product A',
        );

        $token = $this->login(
            $store,
            'manager@example.com',
        );

        $headers = $this->headers(
            $store,
            $token,
        );

        $url =
            '/api/v1/admin/catalog/products/'.
            $product->id.
            '/skus';

        $this
            ->withHeaders($headers)
            ->postJson(
                $url,
                [
                    'code' => 'abc-001',
                ],
            )
            ->assertCreated()
            ->assertJsonPath(
                'data.sku.code',
                'ABC-001',
            );

        $this
            ->withHeaders($headers)
            ->postJson(
                $url,
                [
                    'code' => 'ABC-001',
                ],
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors(
                'code'
            );
    }

    public function test_sku_product_identity_cannot_be_changed_by_patch(): void
    {
        $store = $this->store(
            'Tenant A'
        );

        $this->staff(
            $store['tenant'],
            'manager@example.com',
            SystemRoleCatalog::MANAGER,
        );

        $productA = $this->product(
            $store['tenant'],
            'Product A',
        );

        $productB = $this->product(
            $store['tenant'],
            'Product B',
        );

        $sku = $this->inTenant(
            $store['tenant'],
            fn (): Sku => Sku::query()->create([
                'product_id' => $productA->id,

                'code' => 'SKU-001',

                'track_inventory' => true,

                'is_active' => true,
            ]),
        );

        $token = $this->login(
            $store,
            'manager@example.com',
        );

        $this
            ->withHeaders(
                $this->headers(
                    $store,
                    $token,
                )
            )
            ->patchJson(
                '/api/v1/admin/catalog/skus/'.
                $sku->id,
                [
                    'product_id' => $productB->id,

                    'name_en' => 'Updated SKU',
                ],
            )
            ->assertOk()
            ->assertJsonPath(
                'data.sku.product_id',
                (string) $productA->id,
            );

        $this->inTenant(
            $store['tenant'],
            function () use (
                $sku,
                $productA,
            ): void {
                $fresh = Sku::query()
                    ->findOrFail(
                        $sku->id
                    );

                $this->assertSame(
                    (int) $productA->id,
                    (int) $fresh->product_id,
                );
            },
        );
    }
}
