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
            'orders',
            function (Blueprint $table): void {
                $table->char(
                    'guest_access_token_hash',
                    64,
                )->nullable()->after('public_id');

                $table->unique(
                    [
                        'tenant_id',
                        'guest_access_token_hash',
                    ],
                    'orders_tenant_guest_token_hash_unique',
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
            ALTER TABLE orders
            ADD CONSTRAINT
            orders_guest_access_token_hash_valid
            CHECK (
                guest_access_token_hash IS NULL
                OR guest_access_token_hash ~ '^[0-9a-f]{64}$'
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
                ALTER TABLE orders
                DROP CONSTRAINT IF EXISTS
                orders_guest_access_token_hash_valid
                SQL
            );
        }

        Schema::table(
            'orders',
            function (Blueprint $table): void {
                $table->dropUnique(
                    'orders_tenant_guest_token_hash_unique'
                );

                $table->dropColumn(
                    'guest_access_token_hash'
                );
            },
        );
    }
};
