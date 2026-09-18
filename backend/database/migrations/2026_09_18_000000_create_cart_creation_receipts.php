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
            'cart_creation_receipts',
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

                $table->unsignedBigInteger(
                    'cart_id'
                );

                $table->string(
                    'idempotency_key',
                    120,
                );

                $table->char(
                    'request_hash',
                    64,
                );

                $table->timestampsTz();

                $table->unique(
                    [
                        'tenant_id',
                        'id',
                    ],
                    'cart_creation_receipts_tenant_id_id_unique',
                );

                $table->unique(
                    [
                        'tenant_id',
                        'app_instance_id',
                        'idempotency_key',
                    ],
                    'cart_creation_receipts_app_instance_key_unique',
                );

                $table->foreign(
                    [
                        'tenant_id',
                        'app_instance_id',
                    ],
                    'cart_creation_receipts_tenant_app_instance_foreign',
                )
                    ->references([
                        'tenant_id',
                        'id',
                    ])
                    ->on('app_instances')
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'tenant_id',
                        'cart_id',
                    ],
                    'cart_creation_receipts_tenant_cart_foreign',
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
                        'app_instance_id',
                        'created_at',
                    ],
                    'cart_creation_receipts_lookup_index',
                );
            },
        );

        $this->protectPostgres();
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'cart_creation_receipts'
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
            ALTER TABLE cart_creation_receipts
            ADD CONSTRAINT
            cart_creation_receipts_key_not_blank
            CHECK (
                length(trim(idempotency_key)) > 0
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE cart_creation_receipts
            ADD CONSTRAINT
            cart_creation_receipts_request_hash_valid
            CHECK (
                request_hash ~ '^[0-9a-f]{64}$'
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE cart_creation_receipts
            ENABLE ROW LEVEL SECURITY
            SQL
        );

        DB::statement(
            <<<'SQL'
            CREATE POLICY tenant_isolation_policy
            ON cart_creation_receipts
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
            ALTER TABLE cart_creation_receipts
            FORCE ROW LEVEL SECURITY
            SQL
        );
    }
};
