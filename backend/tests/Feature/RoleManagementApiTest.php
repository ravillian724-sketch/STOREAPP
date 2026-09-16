<?php

namespace Tests\Feature;

use App\Models\AppInstance;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AppInstanceCredentialService;
use App\Services\TenantRbacProvisioner;
use App\Support\Audit\AdminAuditAction;
use App\Support\Authorization\SystemRoleCatalog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class RoleManagementApiTest extends TestCase
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

        app(
            TenantRbacProvisioner::class
        )->provision($tenant);

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

    private function user(
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

                $user->assignRole(
                    $role
                );

                return $user;
            },
        );
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
                $role =
                    Role::query()->create([
                        'code' => $code,
                        'name' => $code,
                        'is_system' => false,
                        'is_active' => true,
                    ]);

                $permissionIds =
                    Permission::query()
                        ->whereIn(
                            'code',
                            $permissionCodes,
                        )
                        ->pluck('id')
                        ->all();

                $role
                    ->permissions()
                    ->sync(
                        $permissionIds
                    );

                return $role;
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
                $store[
                    'instance_token'
                ],
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
            'X-App-Instance-Key' => $store[
                    'instance_token'
                ],

            'Authorization' => 'Bearer '.$token,
        ];
    }

    public function test_owner_can_create_custom_role_with_permissions(): void
    {
        $store =
            $this->store('Tenant A');

        $this->user(
            $store['tenant'],
            'owner@example.com',
            SystemRoleCatalog::OWNER,
        );

        $token = $this->login(
            $store,
            'owner@example.com',
        );

        $response = $this
            ->withHeaders(
                $this->headers(
                    $store,
                    $token,
                )
            )
            ->postJson(
                '/api/v1/admin/roles',
                [
                    'code' => 'SUPERVISOR',

                    'name' => 'Supervisor',

                    'permission_codes' => [
                        'staff.view',
                        'reports.view',
                    ],
                ],
            );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.role.code',
                'supervisor',
            )
            ->assertJsonPath(
                'data.role.is_system',
                false,
            )
            ->assertJsonFragment([
                'code' => 'staff.view',
            ]);

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
                    'code' => 'supervisor',

                    'name' => 'Duplicate',
                ],
            )
            ->assertStatus(422)
            ->assertJsonPath(
                'error.code',
                'ROLE_CODE_ALREADY_IN_USE',
            );
    }

    public function test_unknown_permission_cannot_be_assigned(): void
    {
        $store =
            $this->store('Tenant A');

        $this->user(
            $store['tenant'],
            'owner@example.com',
            SystemRoleCatalog::OWNER,
        );

        $token = $this->login(
            $store,
            'owner@example.com',
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
                    'code' => 'bad_role',

                    'name' => 'Bad Role',

                    'permission_codes' => [
                        'platform.superpower',
                    ],
                ],
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors(
                'permission_codes'
            );
    }

    public function test_system_role_is_immutable(): void
    {
        $store =
            $this->store('Tenant A');

        $owner = $this->user(
            $store['tenant'],
            'owner@example.com',
            SystemRoleCatalog::OWNER,
        );

        $ownerRole = $this->inTenant(
            $store['tenant'],
            fn (): Role => Role::query()
                ->where(
                    'code',
                    SystemRoleCatalog::OWNER,
                )
                ->firstOrFail(),
        );

        $token = $this->login(
            $store,
            $owner->email,
        );

        $this
            ->withHeaders(
                $this->headers(
                    $store,
                    $token,
                )
            )
            ->patchJson(
                '/api/v1/admin/roles/'.
                $ownerRole->id,
                [
                    'name' => 'Changed Owner',
                ],
            )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'SYSTEM_ROLE_IMMUTABLE',
            );
    }

    public function test_cross_tenant_role_cannot_be_modified(): void
    {
        $storeA =
            $this->store('Tenant A');

        $storeB =
            $this->store('Tenant B');

        $this->user(
            $storeA['tenant'],
            'owner@example.com',
            SystemRoleCatalog::OWNER,
        );

        $foreignRole =
            $this->customRole(
                $storeB['tenant'],
                'foreign_role',
                ['staff.view'],
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
            ->patchJson(
                '/api/v1/admin/roles/'.
                $foreignRole->id,
                [
                    'name' => 'Attempted Change',
                ],
            )
            ->assertNotFound()
            ->assertJsonPath(
                'error.code',
                'ROLE_NOT_FOUND',
            );
    }

    public function test_custom_role_code_is_immutable_after_creation(): void
    {
        $store =
            $this->store('Tenant A');

        $this->user(
            $store['tenant'],
            'owner@example.com',
            SystemRoleCatalog::OWNER,
        );

        $role = $this->customRole(
            $store['tenant'],
            'auditor',
            ['reports.view'],
        );

        $token = $this->login(
            $store,
            'owner@example.com',
        );

        $this
            ->withHeaders(
                $this->headers(
                    $store,
                    $token,
                )
            )
            ->patchJson(
                '/api/v1/admin/roles/'.
                $role->id,
                [
                    'code' => 'renamed_role',
                ],
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors(
                'code'
            );
    }

    public function test_staff_without_roles_manage_cannot_create_role(): void
    {
        $store =
            $this->store('Tenant A');

        $this->user(
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
                '/api/v1/admin/roles',
                [
                    'code' => 'blocked_role',

                    'name' => 'Blocked Role',
                ],
            )
            ->assertForbidden()
            ->assertJsonPath(
                'error.code',
                'FORBIDDEN',
            );
    }

    public function test_actor_cannot_mutate_capabilities_of_own_custom_role(): void
    {
        $store =
            $this->store('Tenant A');

        $role = $this->customRole(
            $store['tenant'],
            'security_admin',
            [
                'roles.view',
                'roles.manage',
            ],
        );

        $user = $this->inTenant(
            $store['tenant'],
            function () use (
                $role,
            ): User {
                $user =
                    User::query()->create([
                        'name' => 'Security Admin',

                        'email' => 'security@example.com',

                        'password' => 'Secret123!',

                        'is_active' => true,
                    ]);

                $user->assignRole(
                    $role
                );

                return $user;
            },
        );

        $token = $this->login(
            $store,
            $user->email,
        );

        $headers = $this->headers(
            $store,
            $token,
        );

        $this
            ->withHeaders($headers)
            ->patchJson(
                '/api/v1/admin/roles/'.
                $role->id,
                [
                    'permission_codes' => [
                        'roles.view',
                    ],
                ],
            )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'SELF_ROLE_MUTATION_FORBIDDEN',
            );

        $this
            ->withHeaders($headers)
            ->patchJson(
                '/api/v1/admin/roles/'.
                $role->id,
                [
                    'is_active' => false,
                ],
            )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'SELF_ROLE_MUTATION_FORBIDDEN',
            );
    }

    public function test_deactivating_custom_role_revokes_permission_immediately(): void
    {
        $store =
            $this->store('Tenant A');

        $this->user(
            $store['tenant'],
            'owner@example.com',
            SystemRoleCatalog::OWNER,
        );

        $role = $this->customRole(
            $store['tenant'],
            'staff_viewer',
            ['staff.view'],
        );

        $viewer = $this->inTenant(
            $store['tenant'],
            function () use (
                $role,
            ): User {
                $user =
                    User::query()->create([
                        'name' => 'Viewer',

                        'email' => 'viewer@example.com',

                        'password' => 'Secret123!',

                        'is_active' => true,
                    ]);

                $user->assignRole(
                    $role
                );

                return $user;
            },
        );

        $viewerToken = $this->login(
            $store,
            $viewer->email,
        );

        $this
            ->withHeaders(
                $this->headers(
                    $store,
                    $viewerToken,
                )
            )
            ->getJson(
                '/api/v1/admin/staff'
            )
            ->assertOk();

        $ownerToken = $this->login(
            $store,
            'owner@example.com',
        );

        $this
            ->withHeaders(
                $this->headers(
                    $store,
                    $ownerToken,
                )
            )
            ->patchJson(
                '/api/v1/admin/roles/'.
                $role->id,
                [
                    'is_active' => false,
                ],
            )
            ->assertOk()
            ->assertJsonPath(
                'data.role.is_active',
                false,
            );

        $this
            ->withHeaders(
                $this->headers(
                    $store,
                    $viewerToken,
                )
            )
            ->getJson(
                '/api/v1/admin/staff'
            )
            ->assertForbidden()
            ->assertJsonPath(
                'error.code',
                'FORBIDDEN',
            );
    }

    public function test_stale_tenant_context_cannot_assign_role(): void
    {
        $storeA =
            $this->store('Tenant A');

        $storeB =
            $this->store('Tenant B');

        $userA = $this->user(
            $storeA['tenant'],
            'user-a@example.com',
            SystemRoleCatalog::CASHIER,
        );

        $roleA = $this->inTenant(
            $storeA['tenant'],
            fn (): Role => Role::query()
                ->where(
                    'code',
                    SystemRoleCatalog::MANAGER,
                )
                ->firstOrFail(),
        );

        $context = app(
            TenantContext::class
        );

        $context->set(
            $storeB['tenant']->id
        );

        try {
            $this->expectException(
                LogicException::class
            );

            $userA->assignRole(
                $roleA
            );
        } finally {
            $context->clear();
        }
    }

    public function test_role_creation_writes_audit_log(): void
    {
        $store = $this->store(
            'Tenant A'
        );

        $owner = $this->user(
            $store['tenant'],
            'owner@example.com',
            SystemRoleCatalog::OWNER,
        );

        $token = $this->login(
            $store,
            'owner@example.com',
        );

        $response = $this
            ->withHeaders(
                $this->headers(
                    $store,
                    $token,
                )
            )
            ->postJson(
                '/api/v1/admin/roles',
                [
                    'code' => 'audit_role',
                    'name' => 'Audit Role',
                    'permission_codes' => [
                        'staff.view',
                    ],
                ],
            );

        $response->assertCreated();

        $roleId = (string) $response->json(
            'data.role.id'
        );

        $this->inTenant(
            $store['tenant'],
            function () use (
                $owner,
                $roleId,
            ): void {
                $log = AuditLog::query()
                    ->where(
                        'action',
                        AdminAuditAction::ROLE_CREATED,
                    )
                    ->where(
                        'subject_id',
                        $roleId,
                    )
                    ->firstOrFail();

                $this->assertSame(
                    (int) $owner->id,
                    (int) $log->actor_user_id,
                );

                $this->assertSame(
                    'audit_role',
                    $log->after_values['code'],
                );

                $this->assertSame(
                    'Audit Role',
                    $log->after_values['name'],
                );

                $this->assertContains(
                    'staff.view',
                    $log->after_values[
                        'permission_codes'
                    ],
                );

                $this->assertNotNull(
                    $log->request_id
                );
            },
        );
    }

    public function test_role_update_writes_before_and_after_audit_values(): void
    {
        $store = $this->store(
            'Tenant A'
        );

        $owner = $this->user(
            $store['tenant'],
            'owner@example.com',
            SystemRoleCatalog::OWNER,
        );

        $role = $this->customRole(
            $store['tenant'],
            'audited_role',
            [
                'staff.view',
            ],
        );

        $token = $this->login(
            $store,
            'owner@example.com',
        );

        $this
            ->withHeaders(
                $this->headers(
                    $store,
                    $token,
                )
            )
            ->patchJson(
                '/api/v1/admin/roles/'.
                $role->id,
                [
                    'name' => 'Updated Audit Role',
                    'permission_codes' => [
                        'reports.view',
                    ],
                ],
            )
            ->assertOk();

        $this->inTenant(
            $store['tenant'],
            function () use (
                $owner,
                $role,
            ): void {
                $log = AuditLog::query()
                    ->where(
                        'action',
                        AdminAuditAction::ROLE_UPDATED,
                    )
                    ->where(
                        'subject_id',
                        (string) $role->id,
                    )
                    ->firstOrFail();

                $this->assertSame(
                    (int) $owner->id,
                    (int) $log->actor_user_id,
                );

                $this->assertSame(
                    'audited_role',
                    $log->before_values['name'],
                );

                $this->assertSame(
                    'Updated Audit Role',
                    $log->after_values['name'],
                );

                $this->assertContains(
                    'staff.view',
                    $log->before_values[
                        'permission_codes'
                    ],
                );

                $this->assertContains(
                    'reports.view',
                    $log->after_values[
                        'permission_codes'
                    ],
                );

                $this->assertNotNull(
                    $log->request_id
                );
            },
        );
    }

    public function test_role_create_rolls_back_when_audit_write_fails(): void
    {
        $store = $this->store('Tenant A');

        $this->user(
            $store['tenant'],
            'owner@example.com',
            SystemRoleCatalog::OWNER,
        );

        $token = $this->login(
            $store,
            'owner@example.com',
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
                    '/api/v1/admin/roles',
                    [
                        'code' => 'rollback_role',
                        'name' => 'Rollback Role',
                        'permission_codes' => [
                            'staff.view',
                        ],
                    ],
                )
        );

        $this->inTenant(
            $store['tenant'],
            function (): void {
                $this->assertFalse(
                    Role::query()
                        ->where(
                            'code',
                            'rollback_role',
                        )
                        ->exists()
                );
            },
        );
    }

    public function test_role_update_rolls_back_when_audit_write_fails(): void
    {
        $store = $this->store('Tenant A');

        $this->user(
            $store['tenant'],
            'owner@example.com',
            SystemRoleCatalog::OWNER,
        );

        $role = $this->customRole(
            $store['tenant'],
            'rollback_role',
            ['staff.view'],
        );

        $token = $this->login(
            $store,
            'owner@example.com',
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
                    '/api/v1/admin/roles/'.
                    $role->id,
                    [
                        'name' => 'Changed',
                        'permission_codes' => [
                            'reports.view',
                        ],
                    ],
                )
        );

        $this->inTenant(
            $store['tenant'],
            function () use ($role): void {
                $fresh = Role::query()
                    ->with('permissions')
                    ->findOrFail(
                        $role->id
                    );

                $this->assertSame(
                    'rollback_role',
                    $fresh->name,
                );

                $codes = $fresh->permissions
                    ->pluck('code')
                    ->all();

                $this->assertContains(
                    'staff.view',
                    $codes,
                );

                $this->assertNotContains(
                    'reports.view',
                    $codes,
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
}
