<?php

namespace Tests\Feature;

use App\Models\AppInstance;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sku;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AppInstanceCredentialService;
use App\Services\TenantRbacProvisioner;
use App\Support\Audit\AdminAuditAction;
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

                    'image_url' => 'https://cdn.example.test/products/product-a.png',
                ],
            );

        $productResponse
            ->assertCreated()
            ->assertJsonPath(
                'data.product.name_en',
                'Product A',
            )
            ->assertJsonPath(
                'data.product.image_url',
                'https://cdn.example.test/products/product-a.png',
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

    public function test_product_creation_writes_audit_log(): void
    {
        $store = $this->store(
            'Tenant A'
        );

        $manager = $this->staff(
            $store['tenant'],
            'manager@example.com',
            SystemRoleCatalog::MANAGER,
        );

        $token = $this->login(
            $store,
            'manager@example.com',
        );

        $response = $this
            ->withHeaders(
                $this->headers(
                    $store,
                    $token,
                )
            )
            ->postJson(
                '/api/v1/admin/catalog/products',
                [
                    'name_ar' => 'منتج مدقق',
                    'name_en' => 'Audited Product',
                    'is_active' => true,
                ],
            );

        $response->assertCreated();

        $productId = (string) $response->json(
            'data.product.id'
        );

        $this->inTenant(
            $store['tenant'],
            function () use (
                $manager,
                $productId,
            ): void {
                $log = AuditLog::query()
                    ->where(
                        'action',
                        AdminAuditAction::PRODUCT_CREATED,
                    )
                    ->where(
                        'subject_id',
                        $productId,
                    )
                    ->firstOrFail();

                $this->assertSame(
                    (int) $manager->id,
                    (int) $log->actor_user_id,
                );

                $this->assertNull(
                    $log->before_values
                );

                $this->assertSame(
                    'Audited Product',
                    $log->after_values['name_en'],
                );

                $this->assertTrue(
                    $log->after_values['is_active']
                );

                $this->assertNotNull(
                    $log->request_id
                );
            },
        );
    }

    public function test_product_update_writes_before_and_after_audit_values(): void
    {
        $store = $this->store(
            'Tenant A'
        );

        $manager = $this->staff(
            $store['tenant'],
            'manager@example.com',
            SystemRoleCatalog::MANAGER,
        );

        $product = $this->product(
            $store['tenant'],
            'Original Product',
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
                '/api/v1/admin/catalog/products/'.
                $product->id,
                [
                    'name_en' => 'Updated Product',

                    'image_url' => 'https://cdn.example.test/products/updated.png',

                    'is_active' => false,
                ],
            )
            ->assertOk();

        $this->inTenant(
            $store['tenant'],
            function () use (
                $manager,
                $product,
            ): void {
                $log = AuditLog::query()
                    ->where(
                        'action',
                        AdminAuditAction::PRODUCT_UPDATED,
                    )
                    ->where(
                        'subject_id',
                        (string) $product->id,
                    )
                    ->firstOrFail();

                $this->assertSame(
                    (int) $manager->id,
                    (int) $log->actor_user_id,
                );

                $this->assertSame(
                    'Original Product',
                    $log->before_values['name_en'],
                );

                $this->assertSame(
                    'Updated Product',
                    $log->after_values['name_en'],
                );

                $this->assertNull(
                    $log->before_values['image_url']
                );

                $this->assertSame(
                    'https://cdn.example.test/products/updated.png',
                    $log->after_values['image_url'],
                );

                $this->assertTrue(
                    $log->before_values['is_active']
                );

                $this->assertFalse(
                    $log->after_values['is_active']
                );

                $this->assertNotNull(
                    $log->request_id
                );
            },
        );
    }

    public function test_sku_creation_writes_audit_log(): void
    {
        $store = $this->store(
            'Tenant A'
        );

        $manager = $this->staff(
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

        $response = $this
            ->withHeaders(
                $this->headers(
                    $store,
                    $token,
                )
            )
            ->postJson(
                '/api/v1/admin/catalog/products/'.
                $product->id.
                '/skus',
                [
                    'code' => 'audit-001',

                    'barcode' => '628000099999',
                ],
            );

        $response->assertCreated();

        $skuId = (string) $response->json(
            'data.sku.id'
        );

        $this->inTenant(
            $store['tenant'],
            function () use (
                $manager,
                $product,
                $skuId,
            ): void {
                $log = AuditLog::query()
                    ->where(
                        'action',
                        AdminAuditAction::SKU_CREATED,
                    )
                    ->where(
                        'subject_id',
                        $skuId,
                    )
                    ->firstOrFail();

                $this->assertSame(
                    (int) $manager->id,
                    (int) $log->actor_user_id,
                );

                $this->assertNull(
                    $log->before_values
                );

                $this->assertSame(
                    'AUDIT-001',
                    $log->after_values['code'],
                );

                $this->assertSame(
                    (string) $product->id,
                    $log->after_values['product_id'],
                );

                $this->assertNotNull(
                    $log->request_id
                );
            },
        );
    }

    public function test_sku_update_writes_before_and_after_audit_values(): void
    {
        $store = $this->store(
            'Tenant A'
        );

        $manager = $this->staff(
            $store['tenant'],
            'manager@example.com',
            SystemRoleCatalog::MANAGER,
        );

        $product = $this->product(
            $store['tenant'],
            'Product A',
        );

        $sku = $this->inTenant(
            $store['tenant'],
            fn (): Sku => Sku::query()->create([
                'product_id' => $product->id,

                'code' => 'AUDIT-002',

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
                    'name_en' => 'Updated SKU',

                    'is_active' => false,
                ],
            )
            ->assertOk();

        $this->inTenant(
            $store['tenant'],
            function () use (
                $manager,
                $product,
                $sku,
            ): void {
                $log = AuditLog::query()
                    ->where(
                        'action',
                        AdminAuditAction::SKU_UPDATED,
                    )
                    ->where(
                        'subject_id',
                        (string) $sku->id,
                    )
                    ->firstOrFail();

                $this->assertSame(
                    (int) $manager->id,
                    (int) $log->actor_user_id,
                );

                $this->assertNull(
                    $log->before_values['name_en']
                );

                $this->assertSame(
                    'Updated SKU',
                    $log->after_values['name_en'],
                );

                $this->assertTrue(
                    $log->before_values[
                        'is_active'
                    ]
                );

                $this->assertFalse(
                    $log->after_values[
                        'is_active'
                    ]
                );

                $this->assertSame(
                    (string) $product->id,
                    $log->after_values[
                        'product_id'
                    ],
                );

                $this->assertNotNull(
                    $log->request_id
                );
            },
        );
    }

    public function test_product_create_rolls_back_when_audit_write_fails(): void
    {
        $store = $this->store('Tenant A');

        $this->staff(
            $store['tenant'],
            'manager@example.com',
            SystemRoleCatalog::MANAGER,
        );

        $token = $this->login(
            $store,
            'manager@example.com',
        );

        $this->expectAuditFailure(
            fn () => $this
                ->withHeaders(
                    $this->headers(
                        $store,
                        $token,
                    )
                )
                ->postJson(
                    '/api/v1/admin/catalog/products',
                    [
                        'name_ar' => 'Rollback Product',
                        'name_en' => 'Rollback Product',
                    ],
                )
        );

        $this->inTenant(
            $store['tenant'],
            function (): void {
                $this->assertFalse(
                    Product::query()
                        ->where(
                            'name_en',
                            'Rollback Product',
                        )
                        ->exists()
                );
            },
        );
    }

    public function test_product_update_rolls_back_when_audit_write_fails(): void
    {
        $store = $this->store('Tenant A');

        $this->staff(
            $store['tenant'],
            'manager@example.com',
            SystemRoleCatalog::MANAGER,
        );

        $product = $this->product(
            $store['tenant'],
            'Original Product',
        );

        $token = $this->login(
            $store,
            'manager@example.com',
        );

        $this->expectAuditFailure(
            fn () => $this
                ->withHeaders(
                    $this->headers(
                        $store,
                        $token,
                    )
                )
                ->patchJson(
                    '/api/v1/admin/catalog/products/'.
                    $product->id,
                    [
                        'name_en' => 'Changed Product',
                        'is_active' => false,
                    ],
                )
        );

        $this->inTenant(
            $store['tenant'],
            function () use ($product): void {
                $fresh = Product::query()
                    ->findOrFail(
                        $product->id
                    );

                $this->assertSame(
                    'Original Product',
                    $fresh->name_en,
                );

                $this->assertTrue(
                    (bool) $fresh->is_active
                );
            },
        );
    }

    public function test_sku_create_rolls_back_when_audit_write_fails(): void
    {
        $store = $this->store('Tenant A');

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

        $this->expectAuditFailure(
            fn () => $this
                ->withHeaders(
                    $this->headers(
                        $store,
                        $token,
                    )
                )
                ->postJson(
                    '/api/v1/admin/catalog/products/'.
                    $product->id.
                    '/skus',
                    [
                        'code' => 'rollback-sku',
                    ],
                )
        );

        $this->inTenant(
            $store['tenant'],
            function (): void {
                $this->assertFalse(
                    Sku::query()
                        ->where(
                            'code',
                            'ROLLBACK-SKU',
                        )
                        ->exists()
                );
            },
        );
    }

    public function test_sku_update_rolls_back_when_audit_write_fails(): void
    {
        $store = $this->store('Tenant A');

        $this->staff(
            $store['tenant'],
            'manager@example.com',
            SystemRoleCatalog::MANAGER,
        );

        $product = $this->product(
            $store['tenant'],
            'Product A',
        );

        $sku = $this->inTenant(
            $store['tenant'],
            fn (): Sku => Sku::query()->create([
                'product_id' => $product->id,
                'code' => 'ROLLBACK-SKU',
                'track_inventory' => true,
                'is_active' => true,
            ]),
        );

        $token = $this->login(
            $store,
            'manager@example.com',
        );

        $this->expectAuditFailure(
            fn () => $this
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
                        'name_en' => 'Changed SKU',
                        'is_active' => false,
                    ],
                )
        );

        $this->inTenant(
            $store['tenant'],
            function () use ($sku): void {
                $fresh = Sku::query()
                    ->findOrFail(
                        $sku->id
                    );

                $this->assertNull(
                    $fresh->name_en
                );

                $this->assertTrue(
                    (bool) $fresh->is_active
                );
            },
        );
    }

    private function expectAuditFailure(
        callable $operation,
    ): void {
        $armed = true;

        AuditLog::creating(
            function () use (&$armed): void {
                if ($armed) {
                    throw new \RuntimeException(
                        'Forced audit failure.'
                    );
                }
            }
        );

        $this->withoutExceptionHandling();

        try {
            $operation();

            $this->fail(
                'Expected audit write failure.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Forced audit failure.',
                $exception->getMessage(),
            );
        } finally {
            $armed = false;
            $this->withExceptionHandling();
        }
    }

    public function test_product_audit_minimizes_descriptions_and_tracks_changed_fields(): void
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

        $response = $this
            ->withHeaders(
                $this->headers(
                    $store,
                    $token,
                )
            )
            ->postJson(
                '/api/v1/admin/catalog/products',
                [
                    'name_ar' => 'منتج تدقيق',

                    'name_en' => 'Audit Minimized Product',

                    'description_ar' => 'وصف عربي طويل لا يجب تخزينه في سجل التدقيق',

                    'description_en' => 'Full description must not be copied into the audit log.',

                    'is_active' => true,
                ],
            );

        $response->assertCreated();

        $productId = (string) $response->json(
            'data.product.id'
        );

        $this->inTenant(
            $store['tenant'],
            function () use (
                $productId,
            ): void {
                $log = AuditLog::query()
                    ->where(
                        'action',
                        AdminAuditAction::PRODUCT_CREATED,
                    )
                    ->where(
                        'subject_id',
                        $productId,
                    )
                    ->firstOrFail();

                $this->assertArrayNotHasKey(
                    'description_ar',
                    $log->after_values,
                );

                $this->assertArrayNotHasKey(
                    'description_en',
                    $log->after_values,
                );
            },
        );

        $this
            ->withHeaders(
                $this->headers(
                    $store,
                    $token,
                )
            )
            ->patchJson(
                '/api/v1/admin/catalog/products/'.
                $productId,
                [
                    'description_en' => 'Changed description that must not enter the audit payload.',
                ],
            )
            ->assertOk();

        $this->inTenant(
            $store['tenant'],
            function () use (
                $productId,
            ): void {
                $log = AuditLog::query()
                    ->where(
                        'action',
                        AdminAuditAction::PRODUCT_UPDATED,
                    )
                    ->where(
                        'subject_id',
                        $productId,
                    )
                    ->firstOrFail();

                $this->assertArrayNotHasKey(
                    'description_ar',
                    $log->before_values,
                );

                $this->assertArrayNotHasKey(
                    'description_en',
                    $log->before_values,
                );

                $this->assertArrayNotHasKey(
                    'description_ar',
                    $log->after_values,
                );

                $this->assertArrayNotHasKey(
                    'description_en',
                    $log->after_values,
                );

                $this->assertSame(
                    [
                        'description_en',
                    ],
                    $log->metadata[
                        'changed_fields'
                    ],
                );
            },
        );
    }
}
