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
            'customers',
            function (Blueprint $table): void {
                $table->id();

                $table->foreignId(
                    'tenant_id'
                )
                    ->constrained()
                    ->cascadeOnDelete();

                $table->uuid(
                    'public_id'
                );

                $table->string(
                    'name',
                    200,
                );

                $table->string(
                    'email',
                    254,
                );

                $table->string(
                    'phone',
                    50,
                )->nullable();

                $table->string(
                    'password'
                );

                $table->boolean(
                    'is_active'
                )
                    ->default(true)
                    ->index();

                $table->timestampTz(
                    'email_verified_at'
                )->nullable();

                $table->timestampTz(
                    'last_login_at'
                )->nullable();

                $table->timestampsTz();

                $table->unique(
                    [
                        'tenant_id',
                        'id',
                    ],
                    'customers_tenant_id_id_unique',
                );

                $table->unique(
                    [
                        'tenant_id',
                        'email',
                    ],
                    'customers_tenant_email_unique',
                );

                $table->unique(
                    'public_id',
                    'customers_public_id_unique',
                );

                $table->index(
                    [
                        'tenant_id',
                        'is_active',
                        'created_at',
                    ],
                    'customers_tenant_active_created_index',
                );
            },
        );

        $this->protectPostgres();
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'customers'
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
            ALTER TABLE customers
            ADD CONSTRAINT
            customers_name_not_blank
            CHECK (
                length(trim(name)) > 0
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE customers
            ADD CONSTRAINT
            customers_email_normalized
            CHECK (
                length(trim(email)) > 0
                AND email = lower(trim(email))
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE customers
            ADD CONSTRAINT
            customers_public_id_not_blank
            CHECK (
                length(trim(public_id::text)) > 0
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE customers
            ENABLE ROW LEVEL SECURITY
            SQL
        );

        DB::statement(
            <<<'SQL'
            CREATE POLICY tenant_isolation_policy
            ON customers
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
            ALTER TABLE customers
            FORCE ROW LEVEL SECURITY
            SQL
        );
    }
};
