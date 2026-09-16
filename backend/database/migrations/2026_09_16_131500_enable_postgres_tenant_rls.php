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
    ];

    public function up(): void
    {
        if (
            DB::connection()->getDriverName()
            !== 'pgsql'
        ) {
            return;
        }

        foreach (self::TABLES as $table) {
            DB::statement(
                "ALTER TABLE {$table}
                 ENABLE ROW LEVEL SECURITY"
            );

            DB::statement(
                "CREATE POLICY tenant_isolation_policy
                 ON {$table}
                 USING (
                     tenant_id =
                     NULLIF(
                         current_setting(
                             'app.tenant_id',
                             true
                         ),
                         ''
                     )::bigint
                 )
                 WITH CHECK (
                     tenant_id =
                     NULLIF(
                         current_setting(
                             'app.tenant_id',
                             true
                         ),
                         ''
                     )::bigint
                 )"
            );

            DB::statement(
                "ALTER TABLE {$table}
                 FORCE ROW LEVEL SECURITY"
            );
        }
    }

    public function down(): void
    {
        if (
            DB::connection()->getDriverName()
            !== 'pgsql'
        ) {
            return;
        }

        foreach (
            array_reverse(self::TABLES) as $table
        ) {
            DB::statement(
                "ALTER TABLE {$table}
                 NO FORCE ROW LEVEL SECURITY"
            );

            DB::statement(
                "DROP POLICY IF EXISTS
                 tenant_isolation_policy
                 ON {$table}"
            );

            DB::statement(
                "ALTER TABLE {$table}
                 DISABLE ROW LEVEL SECURITY"
            );
        }
    }
};
