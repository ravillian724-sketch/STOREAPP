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
            'payment_webhook_receipts',
            function (Blueprint $table): void {
                $table->id();

                $table->foreignId(
                    'tenant_id'
                )
                    ->constrained()
                    ->cascadeOnDelete();

                $table->unsignedBigInteger(
                    'payment_attempt_id'
                )->nullable();

                $table->uuid(
                    'public_id'
                )->unique();

                $table->string(
                    'provider_code',
                    64,
                );

                $table->string(
                    'provider_event_id',
                    191,
                );

                $table->string(
                    'provider_reference',
                    191,
                );

                $table->string(
                    'event_type',
                    100,
                );

                /*
                 * Fingerprint only.
                 *
                 * Raw provider payload and signature headers
                 * are deliberately not persisted.
                 */
                $table->char(
                    'payload_sha256',
                    64,
                );

                $table->timestampTz(
                    'occurred_at'
                );

                $table->timestampTz(
                    'received_at'
                );

                $table->timestampTz(
                    'processed_at'
                )->nullable();

                $table->timestampsTz();

                $table->unique(
                    [
                        'tenant_id',
                        'id',
                    ],
                    'payment_webhook_receipts_tenant_id_id_unique',
                );

                /*
                 * Provider event IDs are replay identities.
                 */
                $table->unique(
                    [
                        'tenant_id',
                        'provider_code',
                        'provider_event_id',
                    ],
                    'payment_webhook_receipts_event_unique',
                );

                $table->foreign(
                    [
                        'tenant_id',
                        'payment_attempt_id',
                    ],
                    'payment_webhook_receipts_attempt_foreign',
                )
                    ->references([
                        'tenant_id',
                        'id',
                    ])
                    ->on(
                        'payment_attempts'
                    )
                    ->cascadeOnDelete();

                $table->index(
                    [
                        'tenant_id',
                        'provider_code',
                        'provider_reference',
                    ],
                    'payment_webhook_receipts_reference_index',
                );

                $table->index(
                    [
                        'tenant_id',
                        'processed_at',
                        'received_at',
                    ],
                    'payment_webhook_receipts_processing_index',
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
            ALTER TABLE payment_webhook_receipts
            ADD CONSTRAINT
            payment_webhook_receipts_provider_valid
            CHECK (
                provider_code ~
                '^[a-z0-9][a-z0-9._-]*$'
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE payment_webhook_receipts
            ADD CONSTRAINT
            payment_webhook_receipts_identifiers_nonempty
            CHECK (
                btrim(provider_event_id) <> ''
                AND
                btrim(provider_reference) <> ''
                AND
                btrim(event_type) <> ''
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE payment_webhook_receipts
            ADD CONSTRAINT
            payment_webhook_receipts_hash_valid
            CHECK (
                payload_sha256 ~
                '^[0-9a-f]{64}$'
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE payment_webhook_receipts
            ADD CONSTRAINT
            payment_webhook_receipts_processing_time_valid
            CHECK (
                processed_at IS NULL
                OR
                processed_at >= received_at
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE payment_webhook_receipts
            ENABLE ROW LEVEL SECURITY
            SQL
        );

        DB::statement(
            <<<'SQL'
            CREATE POLICY tenant_isolation_policy
            ON payment_webhook_receipts
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
            ALTER TABLE payment_webhook_receipts
            FORCE ROW LEVEL SECURITY
            SQL
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'payment_webhook_receipts'
        );
    }
};
