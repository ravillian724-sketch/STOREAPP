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
            'app_instances',
            function (Blueprint $table): void {
                $table->unique(
                    [
                        'tenant_id',
                        'id',
                    ],
                    'app_instances_tenant_id_id_unique',
                );
            },
        );

        Schema::create(
            'carts',
            function (Blueprint $table): void {
                $table->id();

                $table->foreignId(
                    'tenant_id'
                )
                    ->constrained()
                    ->cascadeOnDelete();

                $table->unsignedBigInteger(
                    'app_instance_id'
                );

                $table->uuid(
                    'public_id'
                )->unique();

                /*
                 * Never store the future guest cart
                 * bearer token in plaintext.
                 */
                $table->char(
                    'token_hash',
                    64,
                )->unique();

                $table->string(
                    'status',
                    32,
                )->default('active');

                $table->timestampTz(
                    'expires_at'
                )->nullable();

                $table->timestampTz(
                    'converted_at'
                )->nullable();

                $table->timestampTz(
                    'abandoned_at'
                )->nullable();

                $table->timestampTz(
                    'expired_at'
                )->nullable();

                $table->timestampsTz();

                $table->unique(
                    [
                        'tenant_id',
                        'id',
                    ],
                    'carts_tenant_id_id_unique',
                );

                $table->foreign(
                    [
                        'tenant_id',
                        'app_instance_id',
                    ],
                    'carts_tenant_app_instance_foreign',
                )
                    ->references([
                        'tenant_id',
                        'id',
                    ])
                    ->on(
                        'app_instances'
                    )
                    ->restrictOnDelete();

                $table->index(
                    [
                        'tenant_id',
                        'app_instance_id',
                        'status',
                        'expires_at',
                    ],
                    'carts_lookup_index',
                );
            },
        );

        Schema::create(
            'cart_items',
            function (Blueprint $table): void {
                $table->id();

                $table->foreignId(
                    'tenant_id'
                )
                    ->constrained()
                    ->cascadeOnDelete();

                $table->unsignedBigInteger(
                    'cart_id'
                );

                $table->unsignedBigInteger(
                    'sku_id'
                );

                $table->unsignedBigInteger(
                    'location_id'
                );

                /*
                 * Stable identifier for reservation
                 * references. Database IDs are never
                 * required outside this boundary.
                 */
                $table->uuid(
                    'public_id'
                )->unique();

                $table->unsignedBigInteger(
                    'quantity'
                );

                $table->timestampsTz();

                $table->unique(
                    [
                        'tenant_id',
                        'id',
                    ],
                    'cart_items_tenant_id_id_unique',
                );

                /*
                 * Exactly one logical cart line for
                 * a given SKU at a given location.
                 */
                $table->unique(
                    [
                        'tenant_id',
                        'cart_id',
                        'sku_id',
                        'location_id',
                    ],
                    'cart_items_unique_line',
                );

                $table->foreign(
                    [
                        'tenant_id',
                        'cart_id',
                    ],
                    'cart_items_tenant_cart_foreign',
                )
                    ->references([
                        'tenant_id',
                        'id',
                    ])
                    ->on('carts')
                    ->cascadeOnDelete();

                $table->foreign(
                    [
                        'tenant_id',
                        'sku_id',
                    ],
                    'cart_items_tenant_sku_foreign',
                )
                    ->references([
                        'tenant_id',
                        'id',
                    ])
                    ->on('skus')
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'tenant_id',
                        'location_id',
                    ],
                    'cart_items_tenant_location_foreign',
                )
                    ->references([
                        'tenant_id',
                        'id',
                    ])
                    ->on(
                        'inventory_locations'
                    )
                    ->restrictOnDelete();

                $table->index(
                    [
                        'tenant_id',
                        'cart_id',
                        'id',
                    ],
                    'cart_items_cart_index',
                );
            },
        );

        $this->enablePostgresProtection();
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'cart_items'
        );

        Schema::dropIfExists(
            'carts'
        );

        Schema::table(
            'app_instances',
            function (Blueprint $table): void {
                $table->dropUnique(
                    'app_instances_tenant_id_id_unique'
                );
            },
        );
    }

    private function enablePostgresProtection(): void
    {
        if (
            DB::connection()->getDriverName()
            !== 'pgsql'
        ) {
            return;
        }

        DB::statement(
            <<<'SQL'
            ALTER TABLE cart_items
            ADD CONSTRAINT
            cart_items_quantity_positive
            CHECK (quantity > 0)
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE carts
            ADD CONSTRAINT
            carts_status_valid
            CHECK (
                status IN (
                    'active',
                    'converted',
                    'abandoned',
                    'expired'
                )
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE carts
            ADD CONSTRAINT
            carts_terminal_state_consistent
            CHECK (
                (
                    status = 'active'
                    AND converted_at IS NULL
                    AND abandoned_at IS NULL
                    AND expired_at IS NULL
                )
                OR
                (
                    status = 'converted'
                    AND converted_at IS NOT NULL
                    AND abandoned_at IS NULL
                    AND expired_at IS NULL
                )
                OR
                (
                    status = 'abandoned'
                    AND converted_at IS NULL
                    AND abandoned_at IS NOT NULL
                    AND expired_at IS NULL
                )
                OR
                (
                    status = 'expired'
                    AND converted_at IS NULL
                    AND abandoned_at IS NULL
                    AND expired_at IS NOT NULL
                )
            )
            SQL
        );

        foreach (
            [
                'carts',
                'cart_items',
            ] as $table
        ) {
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
                             'storeapp.tenant_id',
                             true
                         ),
                         ''
                     )::bigint
                 )
                 WITH CHECK (
                     tenant_id =
                     NULLIF(
                         current_setting(
                             'storeapp.tenant_id',
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
};
