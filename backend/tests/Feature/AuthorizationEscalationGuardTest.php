<?php

namespace Tests\Feature;

use App\Models\AppInstance;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AppInstanceCredentialService;
use App\Services\TenantRbacProvisioner;
use App\Support\Authorization\PermissionCatalog;
use App\Support\Authorization\SystemRoleCatalog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationEscalationGuardTest extends TestCase
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

    private function store(): array
    {
        $tenant = Tenant::query()->create([
            'name_ar' => 'Tenant A',
            'name_en' => 'Tenant A',
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

    private function customRole(
        Tenant $tenant,
        string $code,
        array $permissionCodes,
    ): Role {
        return $this->inTenant(
            $tenant,
            function () use (
                $code,
                $permissionCodes,
            ): Role {
                $role = Role::query()->create([
                    'code' => $code,
                    'name' => $code,
                    'is_system' => false,
                    'is_active' => true,
                ]);

                $ids = Permission::query()
                    ->whereIn(
                        'code',
                        $permissionCodes,
                    )
                    ->pluck('id')
                    ->all();

                $role->permissions()->sync($ids);

                return $role;
            },
        );
    }

    private function user(
        Tenant $tenant,
        string $email,
        Role|string $role,
    ): User {
        return $this->inTenant(
            $tenant,
            function () use (
                $email,
                $role,
            ): User {
                $user = User::query()->create([
                    'name' => 'Staff',
                    'email' => $email,
                    'password' => 'Secret123!',
                    'is_active' => true,
                ]);

                $resolvedRole =
                    $role instanceof Role
                        ? $role
                        : Role::query()
                            ->where(
                                'code',
                                $role,
                            )
                            ->firstOrFail();

                $user->assignRole(
                    $resolvedRole
                );

                return $user;
            },
        );
    }

    private function roleId(
        Tenant $tenant,
        string $code,
    ): int {
        return $this->inTenant(
            $tenant,
            fn (): int => (int) Role::query()
                ->where('code', $code)
                ->firstOrFail()
                ->id,
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

    public function test_staff_manager_cannot_assign_owner_role(): void
    {
        $store = $this->store();

        $delegated = $this->customRole(
            $store['tenant'],
            'staff_admin',
            [
                'staff.view',
                'staff.manage',
            ],
        );

        $this->user(
            $store['tenant'],
            'staff-admin@example.com',
            $delegated,
        );

        $ownerRoleId = $this->roleId(
            $store['tenant'],
            SystemRoleCatalog::OWNER,
        );

        $token = $this->login(
            $store,
            'staff-admin@example.com',
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
                    'name' => 'Escalated',
                    'email' => 'escalated@example.com',
                    'password' => 'Temporary#123Aa',
                    'role_ids' => [
                        $ownerRoleId,
                    ],
                ],
            )
            ->assertForbidden()
            ->assertJsonPath(
                'error.code',
                'PRIVILEGE_ESCALATION_FORBIDDEN',
            );
    }

    public function test_role_manager_cannot_grant_permission_it_does_not_have(): void
    {
        $store = $this->store();

        $delegated = $this->customRole(
            $store['tenant'],
            'role_admin',
            [
                'roles.view',
                'roles.manage',
            ],
        );

        $this->user(
            $store['tenant'],
            'role-admin@example.com',
            $delegated,
        );

        $token = $this->login(
            $store,
            'role-admin@example.com',
        );

        $this
            ->withHeaders(
                $this->headers(
                    $store,
                    $token,
                )
            )
            ->postJson(
                '/api/v1/admin/roles',
                [
                    'code' => 'financial_admin',
                    'name' => 'Financial Admin',
                    'permission_codes' => [
                        'reports.view',
                    ],
                ],
            )
            ->assertForbidden()
            ->assertJsonPath(
                'error.code',
                'PRIVILEGE_ESCALATION_FORBIDDEN',
            );
    }

    public function test_staff_manager_can_delegate_subset_of_own_permissions(): void
    {
        $store = $this->store();

        $delegated = $this->customRole(
            $store['tenant'],
            'staff_admin',
            [
                'staff.view',
                'staff.manage',
            ],
        );

        $viewer = $this->customRole(
            $store['tenant'],
            'staff_viewer',
            [
                'staff.view',
            ],
        );

        $this->user(
            $store['tenant'],
            'staff-admin@example.com',
            $delegated,
        );

        $token = $this->login(
            $store,
            'staff-admin@example.com',
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
                    'name' => 'Viewer',
                    'email' => 'viewer@example.com',
                    'password' => 'Temporary#123Aa',
                    'role_ids' => [
                        $viewer->id,
                    ],
                ],
            )
            ->assertCreated();
    }

    public function test_last_active_owner_cannot_be_deactivated(): void
    {
        $store = $this->store();

        $owner = $this->user(
            $store['tenant'],
            'owner@example.com',
            SystemRoleCatalog::OWNER,
        );

        $delegated = $this->customRole(
            $store['tenant'],
            'delegated_admin',
            array_keys(
                PermissionCatalog::definitions()
            ),
        );

        $this->user(
            $store['tenant'],
            'admin@example.com',
            $delegated,
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
                $owner->id,
                [
                    'is_active' => false,
                ],
            )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'LAST_ACTIVE_OWNER_REQUIRED',
            );
    }

    public function test_last_active_owner_cannot_lose_owner_role(): void
    {
        $store = $this->store();

        $owner = $this->user(
            $store['tenant'],
            'owner@example.com',
            SystemRoleCatalog::OWNER,
        );

        $delegated = $this->customRole(
            $store['tenant'],
            'delegated_admin',
            array_keys(
                PermissionCatalog::definitions()
            ),
        );

        $this->user(
            $store['tenant'],
            'admin@example.com',
            $delegated,
        );

        $cashierRoleId = $this->roleId(
            $store['tenant'],
            SystemRoleCatalog::CASHIER,
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
                $owner->id,
                [
                    'role_ids' => [
                        $cashierRoleId,
                    ],
                ],
            )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'LAST_ACTIVE_OWNER_REQUIRED',
            );
    }

    public function test_one_owner_can_deactivate_another_when_owner_remains(): void
    {
        $store = $this->store();

        $ownerA = $this->user(
            $store['tenant'],
            'owner-a@example.com',
            SystemRoleCatalog::OWNER,
        );

        $this->user(
            $store['tenant'],
            'owner-b@example.com',
            SystemRoleCatalog::OWNER,
        );

        $token = $this->login(
            $store,
            'owner-b@example.com',
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
                $ownerA->id,
                [
                    'is_active' => false,
                ],
            )
            ->assertOk()
            ->assertJsonPath(
                'data.staff.is_active',
                false,
            );
    }
}
