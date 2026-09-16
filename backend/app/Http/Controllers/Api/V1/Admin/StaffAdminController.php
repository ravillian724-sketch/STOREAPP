<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\AuthorizationGrantGuard;
use App\Support\ApiResponse;
use App\Support\Audit\AdminAuditAction;
use App\Support\Authorization\SystemRoleCatalog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class StaffAdminController extends Controller
{
    public function __construct(
        private readonly AuthorizationGrantGuard $grantGuard,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = max(
            1,
            min(
                100,
                $request->integer('per_page', 25),
            ),
        );

        $page = User::query()
            ->with('roles')
            ->orderBy('id')
            ->simplePaginate($perPage);

        return ApiResponse::success($request, [
            'items' => collect($page->items())
                ->map(
                    fn (User $staff): array => $this->serializeStaff($staff)
                )
                ->values()
                ->all(),

            'pagination' => [
                'current_page' => $page->currentPage(),

                'per_page' => $page->perPage(),

                'has_more' => $page->hasMorePages(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:120',
            ],

            'email' => [
                'required',
                'email',
                'max:255',
            ],

            'password' => [
                'required',
                Password::min(12)
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],

            'role_ids' => [
                'sometimes',
                'array',
                'max:50',
            ],

            'role_ids.*' => [
                'integer',
                'distinct',
            ],
        ]);

        $email = strtolower(
            trim($data['email'])
        );

        if (
            User::query()
                ->where('email', $email)
                ->exists()
        ) {
            return ApiResponse::error(
                $request,
                'EMAIL_ALREADY_IN_USE',
                'Email is already in use.',
                422,
            );
        }

        $roles = $this->resolveRoles(
            $data['role_ids'] ?? []
        );

        $actor = $request->user('sanctum');

        if (
            ! $this->grantGuard->canAssignRoles(
                $actor,
                $roles,
            )
        ) {
            return ApiResponse::error(
                $request,
                'PRIVILEGE_ESCALATION_FORBIDDEN',
                'You cannot assign roles above your own privileges.',
                403,
            );
        }

        $staff = DB::transaction(
            function () use (
                $data,
                $email,
                $roles,
                $actor,
                $request,
            ): User {
                $staff = User::query()->create([
                    'name' => $data['name'],
                    'email' => $email,
                    'password' => $data['password'],
                    'is_active' => true,
                ]);

                $staff->syncRoles($roles);

                $staff->load('roles');

                $this->auditLogger->record(
                    action: AdminAuditAction::STAFF_CREATED,
                    subjectType: 'user',
                    subjectId: $staff->id,
                    actor: $actor,
                    after: $this->auditSnapshot(
                        $staff
                    ),
                    request: $request,
                );

                return $staff;
            }
        );

        $staff->load('roles');

        return ApiResponse::success(
            $request,
            [
                'staff' => $this->serializeStaff($staff),
            ],
            201,
        );
    }

    public function update(
        Request $request,
        string $staffId,
    ): JsonResponse {
        $staff = User::query()->find($staffId);

        if ($staff === null) {
            return ApiResponse::error(
                $request,
                'STAFF_NOT_FOUND',
                'Staff member was not found.',
                404,
            );
        }

        $data = $request->validate([
            'name' => [
                'sometimes',
                'string',
                'max:120',
            ],

            'email' => [
                'sometimes',
                'email',
                'max:255',
            ],

            'is_active' => [
                'sometimes',
                'boolean',
            ],

            'role_ids' => [
                'sometimes',
                'array',
                'max:50',
            ],

            'role_ids.*' => [
                'integer',
                'distinct',
            ],
        ]);

        $actor = $request->user('sanctum');

        if (
            (int) $actor->id ===
                (int) $staff->id &&
            array_key_exists(
                'is_active',
                $data,
            ) &&
            ! (bool) $data['is_active']
        ) {
            return ApiResponse::error(
                $request,
                'SELF_DEACTIVATION_FORBIDDEN',
                'You cannot deactivate your own account.',
                409,
            );
        }

        if (
            (int) $actor->id ===
                (int) $staff->id &&
            array_key_exists(
                'role_ids',
                $data,
            )
        ) {
            return ApiResponse::error(
                $request,
                'SELF_ROLE_CHANGE_FORBIDDEN',
                'You cannot change your own roles.',
                409,
            );
        }

        $email = null;

        if (
            array_key_exists(
                'email',
                $data,
            )
        ) {
            $email = strtolower(
                trim($data['email'])
            );

            if (
                User::query()
                    ->where(
                        'email',
                        $email,
                    )
                    ->whereKeyNot(
                        $staff->id
                    )
                    ->exists()
            ) {
                return ApiResponse::error(
                    $request,
                    'EMAIL_ALREADY_IN_USE',
                    'Email is already in use.',
                    422,
                );
            }
        }

        $roles = null;

        if (
            array_key_exists(
                'role_ids',
                $data,
            )
        ) {
            $roles = $this->resolveRoles(
                $data['role_ids']
            );

            if (
                ! $this->grantGuard->canAssignRoles(
                    $actor,
                    $roles,
                )
            ) {
                return ApiResponse::error(
                    $request,
                    'PRIVILEGE_ESCALATION_FORBIDDEN',
                    'You cannot assign roles above your own privileges.',
                    403,
                );
            }
        }

        $updated = DB::transaction(
            function () use (
                $staff,
                $data,
                $email,
                $roles,
                $actor,
                $request,
            ): bool {
                $staff->load('roles');

                $before =
                    $this->auditSnapshot(
                        $staff
                    );

                if (
                    ! $this->preservesActiveOwner(
                        $staff,
                        $data,
                        $roles,
                    )
                ) {
                    return false;
                }

                if (
                    array_key_exists(
                        'name',
                        $data,
                    )
                ) {
                    $staff->name =
                        $data['name'];
                }

                if ($email !== null) {
                    $staff->email = $email;
                }

                if (
                    array_key_exists(
                        'is_active',
                        $data,
                    )
                ) {
                    $staff->is_active =
                        (bool) $data['is_active'];
                }

                $staff->save();

                if ($roles !== null) {
                    $staff->syncRoles($roles);
                }

                if (
                    array_key_exists(
                        'is_active',
                        $data,
                    ) &&
                    ! (bool) $data['is_active']
                ) {
                    $staff->tokens()->delete();
                }

                $staff->unsetRelation(
                    'roles'
                );

                $staff->load(
                    'roles'
                );

                $this->auditLogger->record(
                    action: AdminAuditAction::STAFF_UPDATED,
                    subjectType: 'user',
                    subjectId: $staff->id,
                    actor: $actor,
                    before: $before,
                    after: $this->auditSnapshot(
                        $staff
                    ),
                    request: $request,
                );

                return true;
            }
        );

        if (! $updated) {
            return ApiResponse::error(
                $request,
                'LAST_ACTIVE_OWNER_REQUIRED',
                'The tenant must retain at least one active owner.',
                409,
            );
        }

        $staff->unsetRelation('roles');
        $staff->load('roles');

        return ApiResponse::success($request, [
            'staff' => $this->serializeStaff($staff),
        ]);
    }

    private function auditSnapshot(
        User $staff,
    ): array {
        $staff->loadMissing(
            'roles'
        );

        return [
            'name' => $staff->name,

            'email' => $staff->email,

            'is_active' => (bool) $staff->is_active,

            'role_codes' => $staff->roles
                ->pluck('code')
                ->sort()
                ->values()
                ->all(),
        ];
    }

    private function preservesActiveOwner(
        User $staff,
        array $data,
        ?Collection $roles,
    ): bool {
        $ownerRole = Role::query()
            ->where(
                'code',
                SystemRoleCatalog::OWNER,
            )
            ->where('is_system', true)
            ->lockForUpdate()
            ->first();

        if ($ownerRole === null) {
            return true;
        }

        $currentlyActiveOwner =
            $staff->is_active &&
            $staff
                ->roles()
                ->where(
                    'roles.id',
                    $ownerRole->id,
                )
                ->exists();

        if (! $currentlyActiveOwner) {
            return true;
        }

        $futureActive =
            array_key_exists(
                'is_active',
                $data,
            )
                ? (bool) $data['is_active']
                : (bool) $staff->is_active;

        $futureOwner =
            $roles === null
                ? true
                : $roles->contains(
                    fn (Role $role): bool => (int) $role->id ===
                        (int) $ownerRole->id
                );

        if ($futureActive && $futureOwner) {
            return true;
        }

        return User::query()
            ->where('is_active', true)
            ->whereKeyNot($staff->id)
            ->whereHas(
                'roles',
                fn ($query) => $query
                    ->where(
                        'roles.id',
                        $ownerRole->id,
                    )
                    ->where(
                        'roles.is_active',
                        true,
                    )
            )
            ->exists();
    }

    private function resolveRoles(
        array $roleIds,
    ): Collection {
        $ids = array_values(
            array_unique(
                array_map(
                    static fn ($id): int => (int) $id,
                    $roleIds,
                ),
            )
        );

        if ($ids === []) {
            return new Collection;
        }

        $roles = Role::query()
            ->where('is_active', true)
            ->whereIn('id', $ids)
            ->get();

        if (
            $roles->count() !==
            count($ids)
        ) {
            throw ValidationException::withMessages([
                'role_ids' => [
                    'One or more selected roles are invalid.',
                ],
            ]);
        }

        return $roles;
    }

    private function serializeStaff(
        User $staff,
    ): array {
        $staff->loadMissing('roles');

        return [
            'id' => (string) $staff->id,
            'name' => $staff->name,
            'email' => $staff->email,
            'is_active' => $staff->is_active,

            'last_login_at' => $staff->last_login_at
                ?->toIso8601String(),

            'roles' => $staff->roles
                ->map(
                    fn (Role $role): array => [
                        'id' => (string) $role->id,

                        'code' => $role->code,

                        'name' => $role->name,

                        'is_system' => $role->is_system,

                        'is_active' => $role->is_active,
                    ]
                )
                ->values()
                ->all(),
        ];
    }
}
