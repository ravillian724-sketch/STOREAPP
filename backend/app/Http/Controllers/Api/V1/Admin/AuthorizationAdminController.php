<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Audit\AuditLogger;
use App\Services\AuthorizationGrantGuard;
use App\Support\ApiResponse;
use App\Support\Audit\AdminAuditAction;
use App\Support\Authorization\PermissionCatalog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuthorizationAdminController extends Controller
{
    public function __construct(
        private readonly AuthorizationGrantGuard $grantGuard,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function roles(
        Request $request,
    ): JsonResponse {
        $roles = Role::query()
            ->with('permissions')
            ->orderBy('code')
            ->get()
            ->map(
                fn (Role $role): array => $this->serializeRole($role)
            )
            ->values()
            ->all();

        return ApiResponse::success(
            $request,
            [
                'roles' => $roles,
            ],
        );
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

        return ApiResponse::success(
            $request,
            [
                'permissions' => $permissions,
            ],
        );
    }

    public function storeRole(
        Request $request,
    ): JsonResponse {
        $request->merge([
            'code' => strtolower(
                trim(
                    (string) $request->input(
                        'code',
                        '',
                    )
                )
            ),
        ]);

        $this->normalizePermissionCodes(
            $request
        );

        $data = $request->validate([
            'code' => [
                'required',
                'string',
                'max:80',
                'regex:/^[a-z][a-z0-9_]{1,79}$/',
            ],

            'name' => [
                'required',
                'string',
                'max:120',
                'regex:/\S/',
            ],

            'permission_codes' => [
                'sometimes',
                'array',
                'max:100',
            ],

            'permission_codes.*' => [
                'string',
                'max:120',
                'distinct',
            ],
        ]);

        if (
            Role::query()
                ->where(
                    'code',
                    $data['code'],
                )
                ->exists()
        ) {
            return ApiResponse::error(
                $request,
                'ROLE_CODE_ALREADY_IN_USE',
                'Role code is already in use.',
                422,
            );
        }

        $permissions =
            $this->resolvePermissions(
                $data['permission_codes']
                    ?? [],
            );

        $actor = $request->user('sanctum');

        if (
            ! $this->grantGuard->canGrantPermissions(
                $actor,
                $permissions->pluck('code')->all(),
            )
        ) {
            return ApiResponse::error(
                $request,
                'PRIVILEGE_ESCALATION_FORBIDDEN',
                'You cannot grant permissions you do not possess.',
                403,
            );
        }

        $role = DB::transaction(
            function () use (
                $data,
                $permissions,
                $actor,
                $request,
            ): Role {
                $role = Role::query()
                    ->create([
                        'code' => $data['code'],

                        'name' => trim(
                            $data['name']
                        ),

                        'is_system' => false,

                        'is_active' => true,
                    ]);

                $role->permissions()->sync(
                    $permissions
                        ->modelKeys()
                );

                $role->load(
                    'permissions'
                );

                $this->auditLogger->record(
                    action: AdminAuditAction::ROLE_CREATED,
                    subjectType: 'role',
                    subjectId: $role->id,
                    actor: $actor,
                    after: $this->auditSnapshot(
                        $role
                    ),
                    request: $request,
                );

                return $role;
            }
        );

        $role->load('permissions');

        return ApiResponse::success(
            $request,
            [
                'role' => $this->serializeRole(
                    $role
                ),
            ],
            201,
        );
    }

    public function updateRole(
        Request $request,
        string $roleId,
    ): JsonResponse {
        $role = Role::query()
            ->with('permissions')
            ->find($roleId);

        if ($role === null) {
            return ApiResponse::error(
                $request,
                'ROLE_NOT_FOUND',
                'Role was not found.',
                404,
            );
        }

        if ($role->is_system) {
            return ApiResponse::error(
                $request,
                'SYSTEM_ROLE_IMMUTABLE',
                'System roles cannot be modified.',
                409,
            );
        }

        $this->normalizePermissionCodes(
            $request
        );

        $data = $request->validate([
            'code' => [
                'prohibited',
            ],

            'name' => [
                'sometimes',
                'string',
                'max:120',
                'regex:/\S/',
            ],

            'is_active' => [
                'sometimes',
                'boolean',
            ],

            'permission_codes' => [
                'sometimes',
                'array',
                'max:100',
            ],

            'permission_codes.*' => [
                'string',
                'max:120',
                'distinct',
            ],
        ]);

        $actor = $request->user(
            'sanctum'
        );

        $actorUsesRole = $actor
            ->roles()
            ->where(
                'roles.id',
                $role->id,
            )
            ->exists();

        $changesOwnCapabilities =
            array_key_exists(
                'permission_codes',
                $data,
            ) ||
            (
                array_key_exists(
                    'is_active',
                    $data,
                ) &&
                ! (bool) $data['is_active']
            );

        if (
            $actorUsesRole &&
            $changesOwnCapabilities
        ) {
            return ApiResponse::error(
                $request,
                'SELF_ROLE_MUTATION_FORBIDDEN',
                'You cannot change the permissions or active state of a role assigned to your own account.',
                409,
            );
        }

        $permissions = null;

        if (
            array_key_exists(
                'permission_codes',
                $data,
            )
        ) {
            $permissions =
                $this->resolvePermissions(
                    $data[
                        'permission_codes'
                    ],
                );
        }

        if (
            $permissions !== null &&
            ! $this->grantGuard->canGrantPermissions(
                $actor,
                $permissions->pluck('code')->all(),
            )
        ) {
            return ApiResponse::error(
                $request,
                'PRIVILEGE_ESCALATION_FORBIDDEN',
                'You cannot grant permissions you do not possess.',
                403,
            );
        }

        DB::transaction(
            function () use (
                $role,
                $data,
                $permissions,
                $actor,
                $request,
            ): void {
                $role->load(
                    'permissions'
                );

                $before =
                    $this->auditSnapshot(
                        $role
                    );

                if (
                    array_key_exists(
                        'name',
                        $data,
                    )
                ) {
                    $role->name = trim(
                        $data['name']
                    );
                }

                if (
                    array_key_exists(
                        'is_active',
                        $data,
                    )
                ) {
                    $role->is_active =
                        (bool) $data[
                            'is_active'
                        ];
                }

                $role->save();

                if (
                    $permissions !== null
                ) {
                    $role
                        ->permissions()
                        ->sync(
                            $permissions
                                ->modelKeys()
                        );
                }

                $role->unsetRelation(
                    'permissions'
                );

                $role->load(
                    'permissions'
                );

                $this->auditLogger->record(
                    action: AdminAuditAction::ROLE_UPDATED,
                    subjectType: 'role',
                    subjectId: $role->id,
                    actor: $actor,
                    before: $before,
                    after: $this->auditSnapshot(
                        $role
                    ),
                    request: $request,
                );
            }
        );

        $role->unsetRelation(
            'permissions'
        );

        $role->load(
            'permissions'
        );

        return ApiResponse::success(
            $request,
            [
                'role' => $this->serializeRole(
                    $role
                ),
            ],
        );
    }

    private function auditSnapshot(
        Role $role,
    ): array {
        $role->loadMissing(
            'permissions'
        );

        return [
            'code' => $role->code,

            'name' => $role->name,

            'is_system' => (bool) $role->is_system,

            'is_active' => (bool) $role->is_active,

            'permission_codes' => $role->permissions
                ->pluck('code')
                ->sort()
                ->values()
                ->all(),
        ];
    }

    private function normalizePermissionCodes(
        Request $request,
    ): void {
        $codes = $request->input(
            'permission_codes'
        );

        if (! is_array($codes)) {
            return;
        }

        $request->merge([
            'permission_codes' => array_map(
                static fn ($code) => is_string($code)
                        ? strtolower(
                            trim($code)
                        )
                        : $code,
                $codes,
            ),
        ]);
    }

    private function resolvePermissions(
        array $codes,
    ): Collection {
        if ($codes === []) {
            return new Collection;
        }

        $allowedCodes = array_keys(
            PermissionCatalog::definitions()
        );

        $unknown = array_values(
            array_diff(
                $codes,
                $allowedCodes,
            )
        );

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'permission_codes' => [
                    'One or more permissions are invalid.',
                ],
            ]);
        }

        $permissions = Permission::query()
            ->whereIn(
                'code',
                $codes,
            )
            ->get();

        if (
            $permissions->count() !==
            count($codes)
        ) {
            throw ValidationException::withMessages([
                'permission_codes' => [
                    'One or more permissions are unavailable.',
                ],
            ]);
        }

        return $permissions;
    }

    private function serializeRole(
        Role $role,
    ): array {
        $role->loadMissing(
            'permissions'
        );

        return [
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
                        'code' => $permission
                            ->code,

                        'name' => $permission
                            ->name,
                    ]
                )
                ->values()
                ->all(),
        ];
    }
}
