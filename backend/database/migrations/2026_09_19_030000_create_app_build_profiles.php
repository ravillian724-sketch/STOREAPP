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
            'store_provisioning_receipts',
            function (Blueprint $table): void {
                $table->unique(
                    [
                        'id',
                        'tenant_id',
                        'app_instance_id',
                    ],
                    'store_provisioning_receipts_identity_unique',
                );
            },
        );

        Schema::create(
            'app_build_profiles',
            function (Blueprint $table): void {
                $table->id();
                $table->uuid('public_id')->unique();

                $table->foreignId(
                    'store_provisioning_receipt_id'
                )->unique();

                $table->foreignId(
                    'tenant_id'
                );

                $table->foreignId(
                    'app_instance_id'
                )->unique();

                $table->string(
                    'slug',
                    64,
                )->unique();

                $table->string(
                    'display_name',
                    100,
                );

                $table->string(
                    'android_application_id',
                    255,
                )->nullable()->unique();

                $table->string(
                    'ios_bundle_id',
                    255,
                )->nullable()->unique();

                $table->string(
                    'version_name',
                    50,
                );

                $table->unsignedBigInteger(
                    'build_number'
                );

                $table->boolean(
                    'is_active'
                )->default(true)->index();

                $table->timestampsTz();

                $table->foreign(
                    [
                        'store_provisioning_receipt_id',
                        'tenant_id',
                        'app_instance_id',
                    ],
                    'app_build_profiles_receipt_identity_foreign',
                )
                    ->references([
                        'id',
                        'tenant_id',
                        'app_instance_id',
                    ])
                    ->on('store_provisioning_receipts')
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'tenant_id',
                        'app_instance_id',
                    ],
                    'app_build_profiles_tenant_instance_foreign',
                )
                    ->references([
                        'tenant_id',
                        'id',
                    ])
                    ->on('app_instances')
                    ->restrictOnDelete();
            },
        );

        if (
            DB::connection()->getDriverName()
            === 'pgsql'
        ) {
            DB::statement(
                <<<'SQL'
                ALTER TABLE app_build_profiles
                ADD CONSTRAINT
                app_build_profiles_slug_valid
                CHECK (
                    slug ~ '^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$'
                )
                SQL
            );

            DB::statement(
                <<<'SQL'
                ALTER TABLE app_build_profiles
                ADD CONSTRAINT
                app_build_profiles_android_id_valid
                CHECK (
                    android_application_id IS NULL
                    OR android_application_id ~ '^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){2,}$'
                )
                SQL
            );

            DB::statement(
                <<<'SQL'
                ALTER TABLE app_build_profiles
                ADD CONSTRAINT
                app_build_profiles_ios_id_valid
                CHECK (
                    ios_bundle_id IS NULL
                    OR ios_bundle_id ~ '^[a-z0-9][a-z0-9-]*(\.[a-z0-9][a-z0-9-]*){2,}$'
                )
                SQL
            );

            DB::statement(
                <<<'SQL'
                ALTER TABLE app_build_profiles
                ADD CONSTRAINT
                app_build_profiles_version_valid
                CHECK (
                    version_name ~ '^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$'
                )
                SQL
            );

            DB::statement(
                <<<'SQL'
                ALTER TABLE app_build_profiles
                ADD CONSTRAINT
                app_build_profiles_build_number_positive
                CHECK (
                    build_number > 0
                )
                SQL
            );

            DB::statement(
                <<<'SQL'
                ALTER TABLE app_build_profiles
                ADD CONSTRAINT
                app_build_profiles_display_name_not_blank
                CHECK (
                    length(trim(display_name)) > 0
                )
                SQL
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'app_build_profiles'
        );

        Schema::table(
            'store_provisioning_receipts',
            function (Blueprint $table): void {
                $table->dropUnique(
                    'store_provisioning_receipts_identity_unique'
                );
            },
        );
    }
};
