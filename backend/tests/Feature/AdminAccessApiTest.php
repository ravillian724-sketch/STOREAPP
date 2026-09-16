<?php

namespace Tests\Feature;

use App\Models\AppInstance;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AppInstanceCredentialService;
use App\Services\TenantRbacProvisioner;
use App\Support\Authorization\SystemRoleCatalog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccessApiTest extends TestCase
{
    use RefreshDatabase;

    private function inTenant(
        Tenant $tenant,
        callable $callback,
    ): mixed {
        $context = app(TenantContext::class);
        $previous = $context->id();

        $context->set($tenant->id);

        try {
            return $callback();
        } finally {
            if ($previous === null) {
                $context->clear();
            } else {
                $context->set($previous);
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

        $instance = AppInstance::query()->create([
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
        string $password = 'Secret123!',
    ): User {
        return $this->inTenant(
            $tenant,
            function () use (
                $email,
                $roleCode,
                $password,
            ): User {
                $user = User::query()->create([
                    'name' => 'Staff',
                    'email' => $email,
                    'password' => $password,
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

    private function roleId(
        Tenant $tenant,
        string $roleCode,
    ): int {
        return $this->inTenant(
            $tenant,
            fn (): int => (int) Role::query()
                ->where(
                    'code',
                    $roleCode,
                )
                ->firstOrFail()
                ->id,
        );
    }

    private function login(
        array $store,
        string $email,
        string $password = 'Secret123!',
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
                    'password' => $password,
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
        string $accessToken,
    ): array {
        return [
            'X-App-Instance-Key' => $store['instance_token'],

            'Authorization' => 'Bearer '.$accessToken,
        ];
    }

    public function test_staff_listing_does_not_cross_tenant_boundary(): void
    {
        $storeA = $this->store('Tenant A');
        $storeB = $this->store('Tenant B');

        $this->staff(
            $storeA['tenant'],
            'admin@example.com',
            SystemRoleCatalog::OWNER,
        );

        $this->staff(
            $storeA['tenant'],
            'local@example.com',
            SystemRoleCatalog::MANAGER,
        );

        $this->staff(
            $storeB['tenant'],
            'foreign@example.com',
            SystemRoleCatalog::OWNER,
        );

        $token = $this->login(
            $storeA,
            'admin@example.com',
        );

        $response = $this
            ->withHeaders(
                $this->headers(
                    $storeA,
                    $token,
                )
            )
            ->getJson(
                '/api/v1/admin/staff?per_page=100'
            );

        $response
            ->assertOk()
            ->assertJsonFragment([
                'email' => 'local@example.com',
            ])
            ->assertJsonMissing([
                'email' => 'foreign@example.com',
            ]);

        $this->assertCount(
            2,
            $response->json('data.items')
        );
    }

    public function test_roles_and_permissions_are_available_without_cross_tenant_role_leakage(): void
    {
        $storeA = $this->store('Tenant A');
        $storeB = $this->store('Tenant B');

        $this->staff(
            $storeA['tenant'],
            'admin@example.com',
            SystemRoleCatalog::OWNER,
        );

        $foreignOwnerId = $this->roleId(
            $storeB['tenant'],
            SystemRoleCatalog::OWNER,
        );

        $token = $this->login(
            $storeA,
            'admin@example.com',
        );

        $roles = $this
            ->withHeaders(
                $this->headers(
                    $storeA,
                    $token,
                )
            )
            ->getJson(
                '/api/v1/admin/roles'
            );

        $roles->assertOk();

        $roleIds = collect(
            $roles->json('data.roles')
        )->pluck('id')->all();

        $this->assertNotContains(
            (string) $foreignOwnerId,
            $roleIds,
        );

        $this
            ->withHeaders(
                $this->headers(
                    $storeA,
                    $token,
                )
            )
            ->getJson(
                '/api/v1/admin/permissions'
            )
            ->assertOk()
            ->assertJsonFragment([
                'code' => 'staff.manage',
            ]);
    }

    public function test_owner_can_create_staff_and_assign_role(): void
    {
        $store = $this->store('Tenant A');

        $this->staff(
            $store['tenant'],
            'admin@example.com',
            SystemRoleCatalog::OWNER,
        );

        $managerRoleId = $this->roleId(
            $store['tenant'],
            SystemRoleCatalog::MANAGER,
        );

        $token = $this->login(
            $store,
            'admin@example.com',
        );

        $response = $this
            ->withHeaders(
                $this->headers(
                    $store,
                    $token,
                )
            )
            ->postJson(
                '/api/v1/admin/staff',
                [
                    'name' => 'New Manager',
                    'email' => 'NEW.STAFF@example.com',
                    'password' => 'Temporary#123Aa',
                    'role_ids' => [
                        $managerRoleId,
                    ],
                ],
            );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.staff.email',
                'new.staff@example.com',
            )
            ->assertJsonFragment([
                'code' => 'manager',
            ]);

        $this->inTenant(
            $store['tenant'],
            function (): void {
                $staff = User::query()
                    ->where(
                        'email',
                        'new.staff@example.com',
                    )
                    ->firstOrFail();

                $this->assertTrue(
                    $staff->roles()
                        ->where(
                            'roles.code',
                            'manager',
                        )
                        ->exists()
                );
            },
        );
    }

    public function test_cross_tenant_role_assignment_is_rejected(): void
    {
        $storeA = $this->store('Tenant A');
        $storeB = $this->store('Tenant B');

        $this->staff(
            $storeA['tenant'],
            'admin@example.com',
            SystemRoleCatalog::OWNER,
        );

        $foreignRoleId = $this->roleId(
            $storeB['tenant'],
            SystemRoleCatalog::MANAGER,
        );

        $token = $this->login(
            $storeA,
            'admin@example.com',
        );

        $this
            ->withHeaders(
                $this->headers(
                    $storeA,
                    $token,
                )
            )
            ->postJson(
                '/api/v1/admin/staff',
                [
                    'name' => 'Invalid Staff',
                    'email' => 'invalid@example.com',
                    'password' => 'Temporary#123Aa',
                    'role_ids' => [
                        $foreignRoleId,
                    ],
                ],
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors(
                'role_ids'
            );
    }

    public function test_deactivating_staff_revokes_all_tokens(): void
    {
        $store = $this->store('Tenant A');

        $this->staff(
            $store['tenant'],
            'admin@example.com',
            SystemRoleCatalog::OWNER,
        );

        $target = $this->staff(
            $store['tenant'],
            'target@example.com',
            SystemRoleCatalog::CASHIER,
        );

        $target->createToken(
            'device-one',
            ['staff'],
        );

        $target->createToken(
            'device-two',
            ['staff'],
        );

        $this->assertSame(
            2,
            $target->tokens()->count(),
        );

        $token = $this->login(
            $store,
            'admin@example.com',
        );

        $this
            ->withHeaders(
                $this->headers(
                    $store,
                    $token,
                )
            )
            ->patchJson(
                '/api/v1/admin/staff/'.
                $target->id,
                [
                    'is_active' => false,
                ],
            )
            ->assertOk()
            ->assertJsonPath(
                'data.staff.is_active',
                false,
            );

        $this->assertSame(
            0,
            $target->tokens()->count(),
        );

        $isActive = $this->inTenant(
            $store['tenant'],
            fn (): bool => (bool) User::query()
                ->findOrFail(
                    $target->id
                )
                ->is_active,
        );

        $this->assertFalse($isActive);
    }

    public function test_admin_cannot_deactivate_self_or_change_own_roles(): void
    {
        $store = $this->store('Tenant A');

        $admin = $this->staff(
            $store['tenant'],
            'admin@example.com',
            SystemRoleCatalog::OWNER,
        );

        $token = $this->login(
            $store,
            'admin@example.com',
        );

        $headers = $this->headers(
            $store,
            $token,
        );

        $this
            ->withHeaders($headers)
            ->patchJson(
                '/api/v1/admin/staff/'.
                $admin->id,
                [
                    'is_active' => false,
                ],
            )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'SELF_DEACTIVATION_FORBIDDEN',
            );

        $this
            ->withHeaders($headers)
            ->patchJson(
                '/api/v1/admin/staff/'.
                $admin->id,
                [
                    'role_ids' => [],
                ],
            )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'SELF_ROLE_CHANGE_FORBIDDEN',
            );
    }

    public function test_staff_without_manage_permission_cannot_create_staff(): void
    {
        $store = $this->store('Tenant A');

        $this->staff(
            $store['tenant'],
            'cashier@example.com',
            SystemRoleCatalog::CASHIER,
        );

        $token = $this->login(
            $store,
            'cashier@example.com',
        );

        $this
            ->withHeaders(
                $this->headers(
                    $store,
                    $token,
                )
            )
            ->postJson(
                '/api/v1/admin/staff',
                [
                    'name' => 'Blocked',
                    'email' => 'blocked@example.com',
                    'password' => 'Temporary#123Aa',
                ],
            )
            ->assertForbidden()
            ->assertJsonPath(
                'error.code',
                'FORBIDDEN',
            );
    }
}
