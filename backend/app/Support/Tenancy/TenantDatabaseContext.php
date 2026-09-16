<?php

namespace App\Support\Tenancy;

use Illuminate\Support\Facades\DB;

final class TenantDatabaseContext
{
    public function set(int $tenantId): void
    {
        if ($this->driver() !== 'pgsql') {
            return;
        }

        DB::connection()->selectOne(
            <<<'SQL'
            SELECT set_config(
                'app.tenant_id',
                ?,
                false
            )
            SQL,
            [(string) $tenantId],
        );
    }

    public function clear(): void
    {
        if ($this->driver() !== 'pgsql') {
            return;
        }

        DB::connection()->selectOne(
            <<<'SQL'
            SELECT set_config(
                'app.tenant_id',
                '',
                false
            )
            SQL,
        );
    }

    private function driver(): string
    {
        return DB::connection()
            ->getDriverName();
    }
}
