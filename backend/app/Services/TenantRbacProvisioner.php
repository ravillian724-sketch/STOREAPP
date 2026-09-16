<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Support\Authorization\PermissionCatalog;
use App\Support\Authorization\SystemRoleCatalog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

final class TenantRbacProvisioner
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function provision(Tenant $tenant): void
    {
        $tenantId = (int) $tenant->getKey();

        if ($tenantId <= 0) {
            throw new LogicException(
                'Tenant must exist before RBAC provisioning.'
            );
        }

        $previousTenantId =
            $this->tenantContext->id();

        DB::transaction(
            function () use (
                $tenantId,
                $previousTenantId,
            ): void {
                $this->tenantContext->set(
                    $tenantId
                );

                try {
                    $this->syncPermissions();
                    $this->syncSystemRoles();
                } finally {
                    if ($previousTenantId === null) {
                        $this->tenantContext->clear();
                    } else {
                        $this->tenantContext->set(
                            $previousTenantId
                        );
                    }
                }
            }
        );
    }

    private function syncPermissions(): void
    {
        foreach (
            PermissionCatalog::definitions() as $code => $name
        ) {
            Permission::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $name],
            );
        }
    }

    private function syncSystemRoles(): void
    {
        foreach (
            SystemRoleCatalog::definitions() as $code => $definition
        ) {
            $role = Role::query()
                ->where('code', $code)
                ->first();

            if (
                $role !== null &&
                ! $role->is_system
            ) {
                throw new LogicException(
                    "Custom role conflicts with system role: {$code}"
                );
            }

            $role ??= new Role;

            $role->fill([
                'code' => $code,
                'name' => $definition['name'],
                'is_system' => true,
                'is_active' => true,
            ]);

            $role->save();

            $permissionCodes = array_values(
                array_unique(
                    $definition['permissions']
                )
            );

            $permissionIds = Permission::query()
                ->whereIn(
                    'code',
                    $permissionCodes
                )
                ->pluck('id')
                ->all();

            if (
                count($permissionIds) !==
                count($permissionCodes)
            ) {
                throw new LogicException(
                    "System role references an unknown permission: {$code}"
                );
            }

            $role->permissions()->sync(
                $permissionIds
            );
        }
    }
}
