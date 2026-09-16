<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Services\TenantRbacProvisioner;
use App\Support\Authorization\PermissionCatalog;
use App\Support\Authorization\SystemRoleCatalog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class RbacProvisioningTest extends TestCase
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

    public function test_provisioner_creates_permission_catalog_and_system_roles(): void
    {
        $tenant = $this->tenant('Tenant A');

        app(TenantRbacProvisioner::class)
            ->provision($tenant);

        $this->assertSame(
            count(PermissionCatalog::definitions()),
            Permission::query()->count(),
        );

        $this->inTenant(
            $tenant,
            function (): void {
                $this->assertSame(
                    count(
                        SystemRoleCatalog::definitions()
                    ),
                    Role::query()
                        ->where('is_system', true)
                        ->count(),
                );
            },
        );
    }

    public function test_provisioning_is_idempotent(): void
    {
        $tenant = $this->tenant('Tenant A');

        $service = app(
            TenantRbacProvisioner::class
        );

        $service->provision($tenant);
        $service->provision($tenant);

        $this->assertSame(
            count(PermissionCatalog::definitions()),
            Permission::query()->count(),
        );

        $this->inTenant(
            $tenant,
            function (): void {
                $this->assertSame(
                    count(
                        SystemRoleCatalog::definitions()
                    ),
                    Role::query()->count(),
                );
            },
        );
    }

    public function test_owner_role_receives_every_platform_permission(): void
    {
        $tenant = $this->tenant('Tenant A');

        app(TenantRbacProvisioner::class)
            ->provision($tenant);

        $this->inTenant(
            $tenant,
            function (): void {
                $owner = Role::query()
                    ->where(
                        'code',
                        SystemRoleCatalog::OWNER,
                    )
                    ->firstOrFail();

                $expected = array_keys(
                    PermissionCatalog::definitions()
                );

                sort($expected);

                $actual = $owner
                    ->permissions()
                    ->pluck('code')
                    ->all();

                sort($actual);

                $this->assertSame(
                    $expected,
                    $actual,
                );
            },
        );
    }

    public function test_system_roles_are_independent_between_tenants(): void
    {
        $tenantA = $this->tenant('Tenant A');
        $tenantB = $this->tenant('Tenant B');

        $service = app(
            TenantRbacProvisioner::class
        );

        $service->provision($tenantA);
        $service->provision($tenantB);

        $ownerA = $this->inTenant(
            $tenantA,
            fn () => Role::query()
                ->where('code', 'owner')
                ->firstOrFail(),
        );

        $ownerB = $this->inTenant(
            $tenantB,
            fn () => Role::query()
                ->where('code', 'owner')
                ->firstOrFail(),
        );

        $this->assertNotSame(
            $ownerA->id,
            $ownerB->id,
        );

        $this->assertSame(
            $tenantA->id,
            $ownerA->tenant_id,
        );

        $this->assertSame(
            $tenantB->id,
            $ownerB->tenant_id,
        );
    }

    public function test_custom_role_cannot_silently_replace_system_role(): void
    {
        $tenant = $this->tenant('Tenant A');

        $this->inTenant(
            $tenant,
            function (): void {
                Role::query()->create([
                    'code' => 'owner',
                    'name' => 'Custom Owner',
                    'is_system' => false,
                ]);
            },
        );

        $this->expectException(
            LogicException::class
        );

        app(TenantRbacProvisioner::class)
            ->provision($tenant);
    }

    public function test_provisioner_restores_previous_tenant_context(): void
    {
        $tenantA = $this->tenant('Tenant A');
        $tenantB = $this->tenant('Tenant B');

        $context = app(TenantContext::class);
        $context->set($tenantA->id);

        app(TenantRbacProvisioner::class)
            ->provision($tenantB);

        $this->assertSame(
            $tenantA->id,
            $context->id(),
        );

        $context->clear();
    }
}
