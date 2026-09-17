<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * PostgreSQL composite foreign keys require an
         * exact unique target key.
         *
         * This key is logically redundant with
         * (tenant_id, id), but it allows the database
         * to prove that the Order and its source Cart
         * carry the same AppInstance identity.
         */
        Schema::table(
            'carts',
            function (Blueprint $table): void {
                $table->unique(
                    [
                        'tenant_id',
                        'id',
                        'app_instance_id',
                    ],
                    'carts_tenant_id_id_app_instance_unique',
                );
            },
        );

        $driver =
            DB::connection()
                ->getDriverName();

        if ($driver === 'pgsql') {
            /*
             * Replace the weaker:
             *
             * Order(tenant, cart)
             *     -> Cart(tenant, id)
             *
             * with:
             *
             * Order(tenant, cart, app_instance)
             *     -> Cart(tenant, id, app_instance)
             *
             * The existing direct Order -> AppInstance FK
             * remains in place as an independent invariant.
             */
            DB::statement(
                <<<'SQL'
                ALTER TABLE orders
                DROP CONSTRAINT
                orders_tenant_cart_foreign
                SQL
            );

            DB::statement(
                <<<'SQL'
                ALTER TABLE orders
                ADD CONSTRAINT
                orders_tenant_cart_app_instance_foreign
                FOREIGN KEY (
                    tenant_id,
                    cart_id,
                    app_instance_id
                )
                REFERENCES carts (
                    tenant_id,
                    id,
                    app_instance_id
                )
                ON DELETE RESTRICT
                SQL
            );

            return;
        }

        if ($driver === 'sqlite') {
            /*
             * SQLite cannot safely add this composite FK
             * to an existing table with ALTER TABLE.
             *
             * Equivalent database enforcement is therefore
             * implemented with INSERT and UPDATE guards.
             */
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
    }

    public function down(): void
    {
        $driver =
            DB::connection()
                ->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(
                <<<'SQL'
                ALTER TABLE orders
                DROP CONSTRAINT
                orders_tenant_cart_app_instance_foreign
                SQL
            );

            DB::statement(
                <<<'SQL'
                ALTER TABLE orders
                ADD CONSTRAINT
                orders_tenant_cart_foreign
                FOREIGN KEY (
                    tenant_id,
                    cart_id
                )
                REFERENCES carts (
                    tenant_id,
                    id
                )
                ON DELETE RESTRICT
                SQL
            );
        }

        if ($driver === 'sqlite') {
            DB::unprepared(
                <<<'SQL'
                DROP TRIGGER IF EXISTS
                orders_cart_app_instance_insert_guard
                SQL
            );

            DB::unprepared(
                <<<'SQL'
                DROP TRIGGER IF EXISTS
                orders_cart_app_instance_update_guard
                SQL
            );
        }

        Schema::table(
            'carts',
            function (Blueprint $table): void {
                $table->dropUnique(
                    'carts_tenant_id_id_app_instance_unique'
                );
            },
        );
    }
};
