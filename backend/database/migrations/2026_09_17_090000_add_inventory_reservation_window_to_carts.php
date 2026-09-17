<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'carts',
            function (Blueprint $table): void {
                $table->timestampTz(
                    'inventory_reserved_until'
                )
                    ->nullable()
                    ->after('expires_at');

                $table->index(
                    [
                        'tenant_id',
                        'status',
                        'inventory_reserved_until',
                    ],
                    'carts_inventory_reservation_window_index',
                );
            },
        );

        if (
            DB::connection()->getDriverName()
            !== 'pgsql'
        ) {
            return;
        }

        DB::statement(
            <<<'SQL'
            ALTER TABLE carts
            ADD CONSTRAINT
            carts_inventory_reserved_until_within_expiry
            CHECK (
                inventory_reserved_until IS NULL
                OR expires_at IS NULL
                OR inventory_reserved_until <= expires_at
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE carts
            ADD CONSTRAINT
            carts_inventory_reservation_active_only
            CHECK (
                status = 'active'
                OR inventory_reserved_until IS NULL
            )
            SQL
        );
    }

    public function down(): void
    {
        if (
            DB::connection()->getDriverName()
            === 'pgsql'
        ) {
            DB::statement(
                <<<'SQL'
                ALTER TABLE carts
                DROP CONSTRAINT IF EXISTS
                carts_inventory_reserved_until_within_expiry
                SQL
            );

            DB::statement(
                <<<'SQL'
                ALTER TABLE carts
                DROP CONSTRAINT IF EXISTS
                carts_inventory_reservation_active_only
                SQL
            );
        }

        Schema::table(
            'carts',
            function (Blueprint $table): void {
                $table->dropIndex(
                    'carts_inventory_reservation_window_index'
                );

                $table->dropColumn(
                    'inventory_reserved_until'
                );
            },
        );
    }
};
