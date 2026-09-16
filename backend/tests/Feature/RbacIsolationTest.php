<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class RbacIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $name): Tenant
    {
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

    private function user(
        Tenant $tenant,
        string $email,
    ): User {
        $context = app(TenantContext::class);
        $context->set($tenant->id);

        try {
            return User::query()->create([
                'name' => 'Staff',
                'email' => $email,
                'password' => 'Secret123!',
            ]);
        } finally {
            $context->clear();
        }
    }

    private function role(
        Tenant $tenant,
        string $code,
    ): Role {
        $context = app(TenantContext::class);
        $context->set($tenant->id);

        try {
            return Role::query()->create([
                'code' => $code,
                'name' => strtoupper($code),
            ]);
        } finally {
            $context->clear();
        }
    }

    public function test_roles_fail_closed_without_tenant_context(): void
    {
        $tenant = $this->tenant('Tenant A');

        $this->role(
            $tenant,
            'manager',
        );

        $this->assertSame(
            0,
            Role::query()->count(),
        );
    }

    public function test_same_role_code_can_exist_in_two_tenants(): void
    {
        $tenantA = $this->tenant('Tenant A');
        $tenantB = $this->tenant('Tenant B');

        $roleA = $this->role($tenantA, 'manager');
        $roleB = $this->role($tenantB, 'manager');

        $this->assertNotSame(
            $roleA->id,
            $roleB->id,
        );
    }

    public function test_user_can_receive_role_and_permission_inside_tenant(): void
    {
        $tenant = $this->tenant('Tenant A');

        $user = $this->user(
            $tenant,
            'staff@example.com',
        );

        $role = $this->role(
            $tenant,
            'manager',
        );

        $permission = Permission::query()->create([
            'code' => 'roles.view',
            'name' => 'View roles',
        ]);

        $context = app(TenantContext::class);
        $context->set($tenant->id);

        try {
            $role->permissions()->attach(
                $permission->id
            );

            $user->assignRole($role);

            $this->assertTrue(
                $user->hasPermission(
                    'roles.view'
                ),
            );

            $this->assertFalse(
                $user->hasPermission(
                    'roles.manage'
                ),
            );
        } finally {
            $context->clear();
        }
    }

    public function test_role_assignment_cannot_cross_tenant_boundary(): void
    {
        $tenantA = $this->tenant('Tenant A');
        $tenantB = $this->tenant('Tenant B');

        $userA = $this->user(
            $tenantA,
            'a@example.com',
        );

        $roleB = $this->role(
            $tenantB,
            'manager',
        );

        $context = app(TenantContext::class);
        $context->set($tenantA->id);

        try {
            $this->expectException(
                LogicException::class
            );

            $userA->assignRole($roleB);
        } finally {
            $context->clear();
        }
    }

    public function test_tenant_cannot_read_other_tenant_roles(): void
    {
        $tenantA = $this->tenant('Tenant A');
        $tenantB = $this->tenant('Tenant B');

        $this->role($tenantA, 'owner');
        $this->role($tenantB, 'manager');

        $context = app(TenantContext::class);
        $context->set($tenantA->id);

        try {
            $this->assertSame(
                ['owner'],
                Role::query()
                    ->pluck('code')
                    ->all(),
            );
        } finally {
            $context->clear();
        }
    }
}
