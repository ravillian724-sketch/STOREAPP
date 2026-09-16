<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use LogicException;

class User extends Authenticatable
{
    use BelongsToTenant;
    use HasApiTokens;
    use HasFactory;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => strtolower(trim($value)),
        );
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(
            Tenant::class
        );
    }

    public function roles(): BelongsToMany
    {
        return $this
            ->belongsToMany(Role::class)
            ->withPivot('tenant_id')
            ->withTimestamps();
    }

    public function assignRole(Role $role): void
    {
        $tenantId =
            $this->requireActiveTenantOwnership();

        if (
            (int) $role->tenant_id !==
            $tenantId
        ) {
            throw new LogicException(
                'Role assignment cannot cross tenant boundaries.'
            );
        }

        $this->roles()->syncWithoutDetaching([
            $role->id => [
                'tenant_id' => $tenantId,
            ],
        ]);
    }

    public function syncRoles(
        iterable $roles,
    ): void {
        $tenantId =
            $this->requireActiveTenantOwnership();

        $assignments = [];

        foreach ($roles as $role) {
            if (
                ! $role instanceof Role ||
                (int) $role->tenant_id !==
                    $tenantId
            ) {
                throw new LogicException(
                    'Role assignment cannot cross tenant boundaries.'
                );
            }

            $assignments[$role->id] = [
                'tenant_id' => $tenantId,
            ];
        }

        $this->roles()->sync(
            $assignments
        );
    }

    public function hasPermission(
        string $permission,
    ): bool {
        return $this
            ->roles()
            ->where(
                'roles.is_active',
                true,
            )
            ->whereHas(
                'permissions',
                fn ($query) => $query->where(
                    'code',
                    $permission,
                ),
            )
            ->exists();
    }

    private function requireActiveTenantOwnership(): int
    {
        $tenantId =
            (int) $this->tenant_id;

        $activeTenantId = (int) app(
            TenantContext::class
        )->requireId();

        if (
            $tenantId <= 0 ||
            $activeTenantId !== $tenantId
        ) {
            throw new LogicException(
                'Staff role assignment requires the owning tenant context.'
            );
        }

        return $tenantId;
    }
}
