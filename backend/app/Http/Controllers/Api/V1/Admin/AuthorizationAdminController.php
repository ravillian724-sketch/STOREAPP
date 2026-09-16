<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthorizationAdminController extends Controller
{
    public function roles(
        Request $request,
    ): JsonResponse {
        $roles = Role::query()
            ->with('permissions')
            ->orderBy('code')
            ->get()
            ->map(
                fn (Role $role): array => [
                    'id' => (string) $role->id,

                    'code' => $role->code,

                    'name' => $role->name,

                    'is_system' => $role->is_system,

                    'is_active' => $role->is_active,

                    'permissions' => $role->permissions
                        ->sortBy('code')
                        ->map(
                            fn (
                                Permission $permission
                            ): array => [
                                'code' => $permission->code,

                                'name' => $permission->name,
                            ]
                        )
                        ->values()
                        ->all(),
                ]
            )
            ->values()
            ->all();

        return ApiResponse::success($request, [
            'roles' => $roles,
        ]);
    }

    public function permissions(
        Request $request,
    ): JsonResponse {
        $permissions = Permission::query()
            ->orderBy('code')
            ->get()
            ->map(
                fn (
                    Permission $permission
                ): array => [
                    'code' => $permission->code,

                    'name' => $permission->name,
                ]
            )
            ->values()
            ->all();

        return ApiResponse::success($request, [
            'permissions' => $permissions,
        ]);
    }
}
