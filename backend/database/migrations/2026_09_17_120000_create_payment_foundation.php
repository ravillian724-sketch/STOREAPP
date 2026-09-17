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
            'payments',
            function (Blueprint $table): void {
                $table->id();

                $table->foreignId(
                    'tenant_id'
                )
                    ->constrained()
                    ->cascadeOnDelete();

                $table->unsignedBigInteger(
                    'order_id'
                );

                $table->uuid(
                    'public_id'
                )->unique();

                $table->string(
                    'status',
                    32,
                )->default('pending');

                $table->char(
                    'currency_code',
                    3,
                );

                /*
                 * Payment monetary values use integer
                 * minor units exactly like Orders.
                 *
                 * SAR 10.50 = 1050.
                 */
                $table->unsignedBigInteger(
                    'amount_minor'
                );

                $table->timestampTz(
                    'authorized_at'
                )->nullable();

                $table->timestampTz(
                    'paid_at'
                )->nullable();

                $table->timestampTz(
                    'cancelled_at'
                )->nullable();

                $table->timestampsTz();

                $table->unique(
                    [
                        'tenant_id',
                        'id',
                    ],
                    'payments_tenant_id_id_unique',
                );

                /*
                 * One payment obligation per Order.
                 *
                 * Gateway retries belong to
                 * payment_attempts, not additional Payment
                 * aggregates.
                 */
                $table->unique(
                    [
                        'tenant_id',
                        'order_id',
                    ],
                    'payments_tenant_order_unique',
                );

                $table->foreign(
                    [
                        'tenant_id',
                        'order_id',
                    ],
                    'payments_tenant_order_foreign',
                )
                    ->references([
                        'tenant_id',
                        'id',
                    ])
                    ->on('orders')
                    ->restrictOnDelete();

                $table->index(
                    [
                        'tenant_id',
                        'status',
                        'created_at',
                    ],
                    'payments_status_lookup_index',
                );
            },
        );

        Schema::create(
            'payment_attempts',
            function (Blueprint $table): void {
                $table->id();

                $table->foreignId(
                    'tenant_id'
                )
                    ->constrained()
                    ->cascadeOnDelete();

                $table->unsignedBigInteger(
                    'payment_id'
                );

                $table->uuid(
                    'public_id'
                )->unique();

                /*
                 * Internal replay identity.
                 *
                 * Never use a client-controlled gateway
                 * reference as the idempotency primitive.
                 */
                $table->string(
                    'idempotency_key',
                    120,
                );

                /*
                 * Provider = integration adapter.
                 *
                 * Examples later:
                 * gateway_card, tabby, tamara, cod.
                 *
                 * Method is customer-facing payment rail,
                 * not card PAN data.
                 */
                $table->string(
                    'provider_code',
                    64,
                );

                $table->string(
                    'method_code',
                    64,
                );

                $table->string(
                    'provider_reference',
                    191,
                )->nullable();

                $table->string(
                    'status',
                    32,
                )->default('created');

                $table->char(
                    'currency_code',
                    3,
                );

                $table->unsignedBigInteger(
                    'amount_minor'
                );

                /*
                 * Safe diagnostic metadata only.
                 *
                 * Never store PAN, CVV, Apple Pay payloads,
                 * or other raw payment credentials here.
                 */
                $table->string(
                    'failure_code',
                    100,
                )->nullable();

                $table->string(
                    'failure_message',
                    500,
                )->nullable();

                $table->timestampTz(
                    'started_at'
                )->nullable();

                $table->timestampTz(
                    'authorized_at'
                )->nullable();

                $table->timestampTz(
                    'succeeded_at'
                )->nullable();

                $table->timestampTz(
                    'failed_at'
                )->nullable();

                $table->timestampTz(
                    'cancelled_at'
                )->nullable();

                $table->timestampsTz();

                $table->unique(
                    [
                        'tenant_id',
                        'id',
                    ],
                    'payment_attempts_tenant_id_id_unique',
                );

                $table->unique(
                    [
                        'tenant_id',
                        'idempotency_key',
                    ],
                    'payment_attempts_tenant_idempotency_unique',
                );

                /*
                 * PostgreSQL and SQLite both permit
                 * multiple NULL values in this UNIQUE key.
                 *
                 * Once the provider assigns a reference,
                 * it cannot identify two attempts for the
                 * same tenant/provider.
                 */
                $table->unique(
                    [
                        'tenant_id',
                        'provider_code',
                        'provider_reference',
                    ],
                    'payment_attempts_provider_reference_unique',
                );

                $table->foreign(
                    [
                        'tenant_id',
                        'payment_id',
                    ],
                    'payment_attempts_tenant_payment_foreign',
                )
                    ->references([
                        'tenant_id',
                        'id',
                    ])
                    ->on('payments')
                    ->cascadeOnDelete();

                $table->index(
                    [
                        'tenant_id',
                        'payment_id',
                        'status',
                    ],
                    'payment_attempts_payment_status_index',
                );
            },
        );

        $this->enablePostgresProtection();
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'payment_attempts'
        );

        Schema::dropIfExists(
            'payments'
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
            ALTER TABLE payments
            ADD CONSTRAINT
            payments_status_valid
            CHECK (
                status IN (
                    'pending',
                    'authorized',
                    'paid',
                    'cancelled'
                )
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE payments
            ADD CONSTRAINT
            payments_currency_valid
            CHECK (
                currency_code ~ '^[A-Z]{3}$'
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE payments
            ADD CONSTRAINT
            payments_amount_positive
            CHECK (amount_minor > 0)
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE payments
            ADD CONSTRAINT
            payments_status_timestamps_consistent
            CHECK (
                (
                    status = 'pending'
                    AND paid_at IS NULL
                    AND cancelled_at IS NULL
                )
                OR
                (
                    status = 'authorized'
                    AND authorized_at IS NOT NULL
                    AND paid_at IS NULL
                    AND cancelled_at IS NULL
                )
                OR
                (
                    status = 'paid'
                    AND paid_at IS NOT NULL
                    AND cancelled_at IS NULL
                )
                OR
                (
                    status = 'cancelled'
                    AND cancelled_at IS NOT NULL
                    AND paid_at IS NULL
                )
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE payment_attempts
            ADD CONSTRAINT
            payment_attempts_status_valid
            CHECK (
                status IN (
                    'created',
                    'pending',
                    'authorized',
                    'succeeded',
                    'failed',
                    'cancelled'
                )
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE payment_attempts
            ADD CONSTRAINT
            payment_attempts_currency_valid
            CHECK (
                currency_code ~ '^[A-Z]{3}$'
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE payment_attempts
            ADD CONSTRAINT
            payment_attempts_amount_positive
            CHECK (amount_minor > 0)
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE payment_attempts
            ADD CONSTRAINT
            payment_attempts_identity_nonempty
            CHECK (
                btrim(idempotency_key) <> ''
                AND btrim(provider_code) <> ''
                AND btrim(method_code) <> ''
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE payment_attempts
            ADD CONSTRAINT
            payment_attempts_status_timestamps_consistent
            CHECK (
                (
                    status IN ('created', 'pending')
                    AND succeeded_at IS NULL
                    AND failed_at IS NULL
                    AND cancelled_at IS NULL
                )
                OR
                (
                    status = 'authorized'
                    AND authorized_at IS NOT NULL
                    AND succeeded_at IS NULL
                    AND failed_at IS NULL
                    AND cancelled_at IS NULL
                )
                OR
                (
                    status = 'succeeded'
                    AND succeeded_at IS NOT NULL
                    AND failed_at IS NULL
                    AND cancelled_at IS NULL
                )
                OR
                (
                    status = 'failed'
                    AND failed_at IS NOT NULL
                    AND succeeded_at IS NULL
                    AND cancelled_at IS NULL
                )
                OR
                (
                    status = 'cancelled'
                    AND cancelled_at IS NOT NULL
                    AND succeeded_at IS NULL
                    AND failed_at IS NULL
                )
            )
            SQL
        );

        foreach (
            [
                'payments',
                'payment_attempts',
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
