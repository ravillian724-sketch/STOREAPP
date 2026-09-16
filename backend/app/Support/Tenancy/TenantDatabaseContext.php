<?php

namespace App\Support\Tenancy;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class TenantDatabaseContext
{
    public function set(int $tenantId): void
    {
        if ($this->driver() !== 'pgsql') {
            return;
        }

        if (
            DB::connection()->transactionLevel() < 1
        ) {
            throw new RuntimeException(
                'PostgreSQL tenant context requires an active transaction.'
            );
        }

        DB::connection()->selectOne(
            <<<'SQL'
            SELECT set_config(
                'storeapp.tenant_id',
                ?,
                true
            )
            SQL,
            [(string) $tenantId],
        );
    }

    public function clear(): void
    {
        if (
            $this->driver() !== 'pgsql' ||
            DB::connection()->transactionLevel() < 1
        ) {
            return;
        }

        try {
            DB::connection()->selectOne(
                <<<'SQL'
                SELECT set_config(
                    'storeapp.tenant_id',
                    '',
                    true
                )
                SQL,
            );
        } catch (QueryException $exception) {
            /*
             * PostgreSQL rejects every command with 25P02
             * after a statement has already aborted the
             * current transaction.
             *
             * Do not mask the original database exception.
             * The transaction rollback will automatically
             * discard the transaction-local tenant setting.
             */
            $sqlState = (string) (
                $exception->errorInfo[0]
                ?? $exception->getCode()
            );

            if ($sqlState === '25P02') {
                return;
            }

            throw $exception;
        }
    }

    private function driver(): string
    {
        return DB::connection()
            ->getDriverName();
    }
}
