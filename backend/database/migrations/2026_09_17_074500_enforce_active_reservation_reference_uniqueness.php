<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const INDEX =
        'inventory_reservations_active_reference_unique';

    public function up(): void
    {
        $driver =
            DB::connection()->getDriverName();

        if (
            $driver !== 'pgsql' &&
            $driver !== 'sqlite'
        ) {
            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX '.
            self::INDEX.
            '
            ON inventory_reservations (
                tenant_id,
                reference_type,
                reference_id
            )
            WHERE
                status = \'active\'
                AND reference_type IS NOT NULL
                AND reference_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        $driver =
            DB::connection()->getDriverName();

        if (
            $driver !== 'pgsql' &&
            $driver !== 'sqlite'
        ) {
            return;
        }

        DB::statement(
            'DROP INDEX IF EXISTS '.
            self::INDEX
        );
    }
};
