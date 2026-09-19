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
            'app_instance_credentials',
            function (Blueprint $table): void {
                $table->unique(
                    [
                        'app_instance_id',
                        'id',
                    ],
                    'app_instance_credentials_instance_id_unique',
                );
            },
        );

        Schema::create(
            'store_provisioning_receipts',
            function (Blueprint $table): void {
                $table->id();

                $table->uuid(
                    'public_id'
                )->unique();

                $table->char(
                    'idempotency_key_hash',
                    64,
                )->unique();

                $table->char(
                    'request_fingerprint',
                    64,
                );

                $table->string(
                    'status',
                    30,
                )->default('provisioning');

                $table->foreignId(
                    'tenant_id'
                )
                    ->nullable()
                    ->constrained()
                    ->restrictOnDelete();

                $table->foreignId(
                    'branch_id'
                )
                    ->nullable()
                    ->constrained()
                    ->restrictOnDelete();

                $table->foreignId(
                    'app_instance_id'
                )
                    ->nullable()
                    ->constrained()
                    ->restrictOnDelete();

                $table->foreignId(
                    'app_instance_credential_id'
                )
                    ->nullable()
                    ->constrained(
                        'app_instance_credentials'
                    )
                    ->restrictOnDelete();

                $table->foreignId(
                    'owner_user_id'
                )
                    ->nullable()
                    ->constrained(
                        'users'
                    )
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'tenant_id',
                        'branch_id',
                    ],
                    'store_provisioning_receipts_tenant_branch_foreign',
                )
                    ->references([
                        'tenant_id',
                        'id',
                    ])
                    ->on('branches')
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'tenant_id',
                        'app_instance_id',
                    ],
                    'store_provisioning_receipts_tenant_app_instance_foreign',
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
                        'owner_user_id',
                    ],
                    'store_provisioning_receipts_tenant_owner_foreign',
                )
                    ->references([
                        'tenant_id',
                        'id',
                    ])
                    ->on('users')
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'app_instance_id',
                        'app_instance_credential_id',
                    ],
                    'store_provisioning_receipts_instance_credential_foreign',
                )
                    ->references([
                        'app_instance_id',
                        'id',
                    ])
                    ->on('app_instance_credentials')
                    ->restrictOnDelete();

                $table->timestampsTz();

                $table->unique(
                    'tenant_id',
                    'store_provisioning_receipts_tenant_unique',
                );

                $table->index(
                    [
                        'status',
                        'created_at',
                    ],
                    'store_provisioning_receipts_status_created_idx',
                );
            },
        );

        if (
            DB::connection()->getDriverName()
            === 'pgsql'
        ) {
            DB::statement(
                <<<'SQL'
                ALTER TABLE store_provisioning_receipts
                ADD CONSTRAINT
                store_provisioning_receipts_status_valid
                CHECK (
                    status IN (
                        'provisioning',
                        'completed'
                    )
                )
                SQL
            );

            DB::statement(
                <<<'SQL'
                ALTER TABLE store_provisioning_receipts
                ADD CONSTRAINT
                store_provisioning_receipts_hashes_valid
                CHECK (
                    idempotency_key_hash ~ '^[0-9a-f]{64}$'
                    AND request_fingerprint ~ '^[0-9a-f]{64}$'
                )
                SQL
            );

            DB::statement(
                <<<'SQL'
                ALTER TABLE store_provisioning_receipts
                ADD CONSTRAINT
                store_provisioning_receipts_completed_links_present
                CHECK (
                    status <> 'completed'
                    OR (
                        tenant_id IS NOT NULL
                        AND branch_id IS NOT NULL
                        AND app_instance_id IS NOT NULL
                        AND app_instance_credential_id IS NOT NULL
                        AND owner_user_id IS NOT NULL
                    )
                )
                SQL
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'store_provisioning_receipts'
        );

        Schema::table(
            'app_instance_credentials',
            function (Blueprint $table): void {
                $table->dropUnique(
                    'app_instance_credentials_instance_id_unique'
                );
            },
        );
    }
};
