<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLES = [
        'branches',
        'users',
        'roles',
        'role_user',
        'products',
        'skus',
        'inventory_locations',
        'stock_ledger_entries',
    ];

    public function up(): void
    {
        $this->replacePolicies(
            'storeapp.tenant_id'
        );
    }

    public function down(): void
    {
        $this->replacePolicies(
            'app.tenant_id'
        );
    }

    private function replacePolicies(
        string $setting,
    ): void {
        if (
            DB::connection()->getDriverName()
            !== 'pgsql'
        ) {
            return;
        }

        foreach (self::TABLES as $table) {
            DB::statement(
                "DROP POLICY IF EXISTS
                 tenant_isolation_policy
                 ON {$table}"
            );

            DB::statement(
                "CREATE POLICY tenant_isolation_policy
                 ON {$table}
                 USING (
                     tenant_id =
                     NULLIF(
                         current_setting(
                             '{$setting}',
                             true
                         ),
                         ''
                     )::bigint
                 )
                 WITH CHECK (
                     tenant_id =
                     NULLIF(
                         current_setting(
                             '{$setting}',
                             true
                         ),
                         ''
                     )::bigint
                 )"
            );

            DB::statement(
                "ALTER TABLE {$table}
                 ENABLE ROW LEVEL SECURITY"
            );

            DB::statement(
                "ALTER TABLE {$table}
                 FORCE ROW LEVEL SECURITY"
            );
        }
    }
};
