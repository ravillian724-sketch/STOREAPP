<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\SystemRoleCatalog;
use Illuminate\Database\Eloquent\Collection;

final class AuthorizationGrantGuard
{
    public function canGrantPermissions(
        User $actor,
        array $permissionCodes,
    ): bool {
        $granted = array_fill_keys(
            $this->effectivePermissionCodes($actor),
            true,
        );

        foreach ($permissionCodes as $code) {
            if (! isset($granted[$code])) {
                return false;
            }
        }

        return true;
    }

    public function canAssignRoles(
        User $actor,
        Collection $roles,
    ): bool {
        $roles->loadMissing('permissions');

        foreach ($roles as $role) {
            if (
                $role->is_system &&
                $role->code === SystemRoleCatalog::OWNER &&
                ! $this->isOwner($actor)
            ) {
                return false;
            }
        }

        $permissionCodes = $roles
            ->flatMap(
                fn (Role $role) => $role->permissions->pluck('code')
            )
            ->unique()
            ->values()
            ->all();

        return $this->canGrantPermissions(
            $actor,
            $permissionCodes,
        );
    }

    private function effectivePermissionCodes(
        User $actor,
    ): array {
        return $actor
            ->roles()
            ->where('roles.is_active', true)
            ->with('permissions')
            ->get()
            ->flatMap(
                fn (Role $role) => $role->permissions->pluck('code')
            )
            ->unique()
            ->values()
            ->all();
    }

    private function isOwner(
        User $actor,
    ): bool {
        return $actor
            ->roles()
            ->where(
                'roles.code',
                SystemRoleCatalog::OWNER,
            )
            ->where(
                'roles.is_system',
                true,
            )
            ->where(
                'roles.is_active',
                true,
            )
            ->exists();
    }
}
