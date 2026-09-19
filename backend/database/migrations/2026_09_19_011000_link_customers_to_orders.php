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
                $table->unsignedBigInteger(
                    'customer_id'
                )
                    ->nullable()
                    ->after('app_instance_id');

                $table->foreign(
                    [
                        'tenant_id',
                        'customer_id',
                    ],
                    'orders_tenant_customer_foreign',
                )
                    ->references([
                        'tenant_id',
                        'id',
                    ])
                    ->on('customers')
                    ->restrictOnDelete();

                $table->index(
                    [
                        'tenant_id',
                        'customer_id',
                        'created_at',
                    ],
                    'orders_customer_history_index',
                );
            },
        );

        $this->restoreSqliteCoherenceGuards();
    }

    public function down(): void
    {
        Schema::table(
            'orders',
            function (Blueprint $table): void {
                $table->dropForeign(
                    'orders_tenant_customer_foreign'
                );

                $table->dropIndex(
                    'orders_customer_history_index'
                );

                $table->dropColumn(
                    'customer_id'
                );
            },
        );

        $this->restoreSqliteCoherenceGuards();
    }

    private function restoreSqliteCoherenceGuards(): void
    {
        if (
            DB::connection()->getDriverName()
            !== 'sqlite'
        ) {
            return;
        }

        DB::unprepared(
            'DROP TRIGGER IF EXISTS '
            .'orders_cart_app_instance_insert_guard'
        );

        DB::unprepared(
            'DROP TRIGGER IF EXISTS '
            .'orders_cart_app_instance_update_guard'
        );

        DB::unprepared(
            <<<'SQL'
            CREATE TRIGGER
            orders_cart_app_instance_insert_guard
            BEFORE INSERT ON orders
            FOR EACH ROW
            WHEN NOT EXISTS (
                SELECT 1
                FROM carts
                WHERE carts.tenant_id =
                    NEW.tenant_id
                  AND carts.id =
                    NEW.cart_id
                  AND carts.app_instance_id =
                    NEW.app_instance_id
            )
            BEGIN
                SELECT RAISE(
                    ABORT,
                    'order cart app instance mismatch'
                );
            END
            SQL
        );

        DB::unprepared(
            <<<'SQL'
            CREATE TRIGGER
            orders_cart_app_instance_update_guard
            BEFORE UPDATE OF
                tenant_id,
                cart_id,
                app_instance_id
            ON orders
            FOR EACH ROW
            WHEN NOT EXISTS (
                SELECT 1
                FROM carts
                WHERE carts.tenant_id =
                    NEW.tenant_id
                  AND carts.id =
                    NEW.cart_id
                  AND carts.app_instance_id =
                    NEW.app_instance_id
            )
            BEGIN
                SELECT RAISE(
                    ABORT,
                    'order cart app instance mismatch'
                );
            END
            SQL
        );
    }
};
