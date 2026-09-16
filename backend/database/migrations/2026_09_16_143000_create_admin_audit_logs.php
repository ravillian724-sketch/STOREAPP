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
         * Required for the composite tenant + actor FK.
         */
        Schema::table(
            'users',
            function (Blueprint $table): void {
                $table->unique(
                    ['tenant_id', 'id'],
                    'users_tenant_id_id_unique',
                );
            },
        );

        Schema::create(
            'audit_logs',
            function (Blueprint $table): void {
                $table->id();

                /*
                 * Audit history must not disappear merely
                 * because someone tries to delete a tenant.
                 */
                $table->foreignId('tenant_id')
                    ->constrained()
                    ->restrictOnDelete();

                /*
                 * Nullable for system/background actions.
                 */
                $table->unsignedBigInteger(
                    'actor_user_id'
                )->nullable();

                $table->string(
                    'action',
                    120,
                );

                $table->string(
                    'subject_type',
                    160,
                );

                $table->string(
                    'subject_id',
                    160,
                )->nullable();

                $table->string(
                    'request_id',
                    255,
                )->nullable();

                $table->string(
                    'http_method',
                    16,
                )->nullable();

                $table->string(
                    'path',
                    500,
                )->nullable();

                $table->string(
                    'ip_address',
                    45,
                )->nullable();

                $table->json(
                    'before_values'
                )->nullable();

                $table->json(
                    'after_values'
                )->nullable();

                $table->json(
                    'metadata'
                )->nullable();

                $table->timestampTz(
                    'occurred_at'
                );

                $table->timestampTz(
                    'created_at'
                );

                /*
                 * Actor must belong to the same tenant.
                 * Staff is deactivated rather than hard deleted,
                 * so RESTRICT preserves audit identity.
                 */
                $table->foreign(
                    [
                        'tenant_id',
                        'actor_user_id',
                    ],
                    'audit_logs_tenant_actor_foreign',
                )->references(
                    [
                        'tenant_id',
                        'id',
                    ]
                )->on(
                    'users'
                )->restrictOnDelete();

                $table->index(
                    [
                        'tenant_id',
                        'occurred_at',
                        'id',
                    ],
                    'audit_logs_tenant_time_index',
                );

                $table->index(
                    [
                        'tenant_id',
                        'action',
                        'occurred_at',
                    ],
                    'audit_logs_tenant_action_index',
                );

                $table->index(
                    [
                        'tenant_id',
                        'subject_type',
                        'subject_id',
                    ],
                    'audit_logs_tenant_subject_index',
                );

                $table->index(
                    [
                        'tenant_id',
                        'actor_user_id',
                        'occurred_at',
                    ],
                    'audit_logs_tenant_actor_index',
                );
            },
        );

        $this->enablePostgresRls();
        $this->enablePostgresImmutability();
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'audit_logs'
        );

        if (
            DB::connection()->getDriverName()
            === 'pgsql'
        ) {
            DB::statement(
                <<<'SQL'
                DROP FUNCTION IF EXISTS
                storeapp_reject_audit_log_mutation()
                SQL
            );
        }

        Schema::table(
            'users',
            function (Blueprint $table): void {
                $table->dropUnique(
                    'users_tenant_id_id_unique'
                );
            },
        );
    }

    private function enablePostgresRls(): void
    {
        if (
            DB::connection()->getDriverName()
            !== 'pgsql'
        ) {
            return;
        }

        DB::statement(
            <<<'SQL'
            ALTER TABLE audit_logs
            ENABLE ROW LEVEL SECURITY
            SQL
        );

        DB::statement(
            <<<'SQL'
            CREATE POLICY tenant_isolation_policy
            ON audit_logs
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
            ALTER TABLE audit_logs
            FORCE ROW LEVEL SECURITY
            SQL
        );
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
            storeapp_reject_audit_log_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION
                    'Audit log entries are immutable'
                    USING ERRCODE = '55000';
            END;
            $$;

            CREATE TRIGGER
            audit_logs_immutable
            BEFORE UPDATE OR DELETE
            ON audit_logs
            FOR EACH ROW
            EXECUTE FUNCTION
            storeapp_reject_audit_log_mutation();
            SQL
        );
    }
};
