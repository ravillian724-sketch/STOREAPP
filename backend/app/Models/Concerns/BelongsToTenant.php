<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Support\Tenancy\TenantContext;
use LogicException;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            $tenantId = app(TenantContext::class)->requireId();

            // Never trust caller-supplied tenant_id.
            $model->setAttribute('tenant_id', $tenantId);
        });

        static::updating(function ($model): void {
            if ($model->isDirty('tenant_id')) {
                throw new LogicException(
                    'Tenant ownership cannot be changed.'
                );
            }
        });
    }
}
