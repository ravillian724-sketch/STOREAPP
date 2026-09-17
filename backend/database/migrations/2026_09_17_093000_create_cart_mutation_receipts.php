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
            'cart_mutation_receipts',
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

                $table->string(
                    'idempotency_key',
                    120,
                );

                $table->string(
                    'operation',
                    32,
                );

                $table->char(
                    'request_hash',
                    64,
                );

                /*
                 * We intentionally store the stable public
                 * identity rather than a hard FK to the item.
                 *
                 * A later delete must not destroy the receipt
                 * proving that the mutation already happened.
                 */
                $table->uuid(
                    'result_item_public_id'
                )->nullable();

                $table->timestampsTz();

                $table->unique(
                    [
                        'tenant_id',
                        'id',
                    ],
                    'cart_mutation_receipts_tenant_id_id_unique',
                );

                /*
                 * A key is unique only inside one cart.
                 * The same opaque client key may therefore
                 * safely exist in another cart or tenant.
                 */
                $table->unique(
                    [
                        'tenant_id',
                        'cart_id',
                        'idempotency_key',
                    ],
                    'cart_mutation_receipts_cart_key_unique',
                );

                $table->foreign(
                    [
                        'tenant_id',
                        'cart_id',
                    ],
                    'cart_mutation_receipts_tenant_cart_foreign',
                )
                    ->references([
                        'tenant_id',
                        'id',
                    ])
                    ->on('carts')
                    ->cascadeOnDelete();

                $table->index(
                    [
                        'tenant_id',
                        'cart_id',
                        'created_at',
                    ],
                    'cart_mutation_receipts_lookup_index',
                );
            },
        );

        $this->protectPostgres();
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'cart_mutation_receipts'
        );
    }

    private function protectPostgres(): void
    {
        if (
            DB::connection()->getDriverName()
            !== 'pgsql'
        ) {
            return;
        }

        DB::statement(
            <<<'SQL'
            ALTER TABLE cart_mutation_receipts
            ADD CONSTRAINT
            cart_mutation_receipts_key_not_blank
            CHECK (
                length(trim(idempotency_key)) > 0
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE cart_mutation_receipts
            ADD CONSTRAINT
            cart_mutation_receipts_operation_not_blank
            CHECK (
                length(trim(operation)) > 0
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE cart_mutation_receipts
            ADD CONSTRAINT
            cart_mutation_receipts_request_hash_valid
            CHECK (
                request_hash ~ '^[0-9a-f]{64}$'
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE cart_mutation_receipts
            ENABLE ROW LEVEL SECURITY
            SQL
        );

        DB::statement(
            <<<'SQL'
            CREATE POLICY tenant_isolation_policy
            ON cart_mutation_receipts
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
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE cart_mutation_receipts
            FORCE ROW LEVEL SECURITY
            SQL
        );
    }
};
