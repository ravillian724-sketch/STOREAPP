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
            'branches',
            function (Blueprint $table): void {
                $table->unique(
                    ['tenant_id', 'id'],
                    'branches_tenant_id_id_unique',
                );
            },
        );

        Schema::create(
            'inventory_locations',
            function (Blueprint $table): void {
                $table->id();

                $table->foreignId('tenant_id')
                    ->constrained()
                    ->cascadeOnDelete();

                $table->unsignedBigInteger(
                    'branch_id'
                )->nullable();

                $table->string(
                    'code',
                    100,
                );

                $table->string('name_ar');
                $table->string('name_en');

                $table->string(
                    'type',
                    50,
                );

                $table->boolean(
                    'is_active'
                )->default(true);

                $table->timestamps();

                $table->unique(
                    ['tenant_id', 'id'],
                    'inventory_locations_tenant_id_id_unique',
                );

                $table->unique(
                    ['tenant_id', 'code'],
                    'inventory_locations_tenant_code_unique',
                );

                $table->foreign(
                    [
                        'tenant_id',
                        'branch_id',
                    ],
                    'inventory_locations_tenant_branch_foreign',
                )->references(
                    [
                        'tenant_id',
                        'id',
                    ]
                )->on(
                    'branches'
                )->restrictOnDelete();

                $table->index(
                    [
                        'tenant_id',
                        'is_active',
                        'id',
                    ],
                    'inventory_locations_tenant_active_index',
                );
            },
        );

        Schema::create(
            'stock_ledger_entries',
            function (Blueprint $table): void {
                $table->id();

                $table->foreignId('tenant_id')
                    ->constrained()
                    ->cascadeOnDelete();

                $table->unsignedBigInteger(
                    'sku_id'
                );

                $table->unsignedBigInteger(
                    'location_id'
                );

                $table->string(
                    'movement_type',
                    64,
                );

                // Signed units. Positive adds stock,
                // negative removes stock.
                $table->bigInteger(
                    'quantity_delta'
                );

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
                    'occurred_at'
                );

                $table->timestampTz(
                    'created_at'
                );

                $table->unique(
                    [
                        'tenant_id',
                        'idempotency_key',
                    ],
                    'stock_ledger_tenant_idempotency_unique',
                );

                $table->foreign(
                    [
                        'tenant_id',
                        'sku_id',
                    ],
                    'stock_ledger_tenant_sku_foreign',
                )->references(
                    [
                        'tenant_id',
                        'id',
                    ]
                )->on(
                    'skus'
                )->restrictOnDelete();

                $table->foreign(
                    [
                        'tenant_id',
                        'location_id',
                    ],
                    'stock_ledger_tenant_location_foreign',
                )->references(
                    [
                        'tenant_id',
                        'id',
                    ]
                )->on(
                    'inventory_locations'
                )->restrictOnDelete();

                $table->index(
                    [
                        'tenant_id',
                        'sku_id',
                        'location_id',
                        'occurred_at',
                    ],
                    'stock_ledger_balance_index',
                );

                $table->index(
                    [
                        'tenant_id',
                        'reference_type',
                        'reference_id',
                    ],
                    'stock_ledger_reference_index',
                );
            },
        );

        $this->enableRls();

        $this->enablePostgresImmutability();
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'stock_ledger_entries'
        );

        Schema::dropIfExists(
            'inventory_locations'
        );

        if (
            DB::connection()->getDriverName()
            === 'pgsql'
        ) {
            DB::statement(
                <<<'SQL'
                DROP FUNCTION IF EXISTS
                storeapp_reject_stock_ledger_mutation()
                SQL
            );
        }

        Schema::table(
            'branches',
            function (Blueprint $table): void {
                $table->dropUnique(
                    'branches_tenant_id_id_unique'
                );
            },
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

        foreach (
            [
                'inventory_locations',
                'stock_ledger_entries',
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

    private function enablePostgresImmutability(): void
    {
        if (
            DB::connection()->getDriverName()
            !== 'pgsql'
        ) {
            return;
        }

        DB::unprepared(
            <<<'SQL'
            CREATE OR REPLACE FUNCTION
            storeapp_reject_stock_ledger_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION
                    'Stock ledger entries are immutable'
                    USING ERRCODE = '55000';
            END;
            $$;

            CREATE TRIGGER
            stock_ledger_entries_immutable
            BEFORE UPDATE OR DELETE
            ON stock_ledger_entries
            FOR EACH ROW
            EXECUTE FUNCTION
            storeapp_reject_stock_ledger_mutation();
            SQL
        );
    }
};
