<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'inventory_positions',
            function (Blueprint $table): void {
                $table->id();

                $table->foreignId(
                    'tenant_id'
                )
                    ->constrained()
                    ->cascadeOnDelete();

                $table->unsignedBigInteger(
                    'sku_id'
                );

                $table->unsignedBigInteger(
                    'location_id'
                );

                $table->timestampsTz();

                $table->unique(
                    [
                        'tenant_id',
                        'id',
                    ],
                    'inventory_positions_tenant_id_id_unique',
                );

                $table->unique(
                    [
                        'tenant_id',
                        'sku_id',
                        'location_id',
                    ],
                    'inventory_positions_tenant_sku_location_unique',
                );

                $table->foreign(
                    [
                        'tenant_id',
                        'sku_id',
                    ],
                    'inventory_positions_tenant_sku_foreign',
                )
                    ->references([
                        'tenant_id',
                        'id',
                    ])
                    ->on('skus')
                    ->cascadeOnDelete();

                $table->foreign(
                    [
                        'tenant_id',
                        'location_id',
                    ],
                    'inventory_positions_tenant_location_foreign',
                )
                    ->references([
                        'tenant_id',
                        'id',
                    ])
                    ->on(
                        'inventory_locations'
                    )
                    ->cascadeOnDelete();
            },
        );

        Schema::create(
            'inventory_reservations',
            function (Blueprint $table): void {
                $table->id();

                $table->foreignId(
                    'tenant_id'
                )
                    ->constrained()
                    ->cascadeOnDelete();

                $table->unsignedBigInteger(
                    'sku_id'
                );

                $table->unsignedBigInteger(
                    'location_id'
                );

                $table->unsignedBigInteger(
                    'quantity'
                );

                $table->string(
                    'status',
                    32,
                )->default('active');

                $table->string(
                    'idempotency_key',
                    120,
                );

                $table->string(
                    'reference_type',
                    100,
                )->nullable();

                $table->string(
                    'reference_id',
                    120,
                )->nullable();

                $table->timestampTz(
                    'expires_at'
                )->nullable();

                $table->timestampTz(
                    'released_at'
                )->nullable();

                $table->timestampTz(
                    'consumed_at'
                )->nullable();

                $table->timestampTz(
                    'expired_at'
                )->nullable();

                $table->timestampsTz();

                $table->unique(
                    [
                        'tenant_id',
                        'idempotency_key',
                    ],
                    'inventory_reservations_tenant_idempotency_unique',
                );

                $table->foreign(
                    [
                        'tenant_id',
                        'sku_id',
                    ],
                    'inventory_reservations_tenant_sku_foreign',
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
                    'inventory_reservations_tenant_location_foreign',
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
                        'sku_id',
                        'location_id',
                        'status',
                        'expires_at',
                    ],
                    'inventory_reservations_availability_index',
                );

                $table->index(
                    [
                        'tenant_id',
                        'reference_type',
                        'reference_id',
                    ],
                    'inventory_reservations_reference_index',
                );
            },
        );

        $this->enableRls();
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'inventory_reservations'
        );

        Schema::dropIfExists(
            'inventory_positions'
        );
    }

    private function enableRls(): void
    {
        if (
            DB::connection()->getDriverName()
            !== 'pgsql'
        ) {
            return;
        }

        DB::statement(
            <<<'SQL'
            ALTER TABLE inventory_reservations
            ADD CONSTRAINT
            inventory_reservations_quantity_positive
            CHECK (quantity > 0)
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE inventory_reservations
            ADD CONSTRAINT
            inventory_reservations_status_valid
            CHECK (
                status IN (
                    'active',
                    'released',
                    'consumed',
                    'expired'
                )
            )
            SQL
        );

        foreach (
            [
                'inventory_positions',
                'inventory_reservations',
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
