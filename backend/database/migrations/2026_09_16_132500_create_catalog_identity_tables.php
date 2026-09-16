<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RLS_TABLES = [
        'products',
        'skus',
    ];

    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('name_ar');
            $table->string('name_en');

            $table->text('description_ar')
                ->nullable();

            $table->text('description_en')
                ->nullable();

            $table->boolean('is_active')
                ->default(true);

            $table->timestamps();

            // Required for same-tenant composite foreign keys.
            $table->unique(
                ['tenant_id', 'id'],
                'products_tenant_id_id_unique',
            );

            $table->index(
                ['tenant_id', 'is_active', 'id'],
                'products_tenant_active_id_index',
            );
        });

        Schema::create('skus', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedBigInteger(
                'product_id'
            );

            // Tenant-local operational SKU code.
            $table->string(
                'code',
                100,
            );

            $table->string(
                'barcode',
                100,
            )->nullable();

            // Optional variant/package label.
            $table->string('name_ar')
                ->nullable();

            $table->string('name_en')
                ->nullable();

            $table->boolean(
                'track_inventory'
            )->default(true);

            $table->boolean(
                'is_active'
            )->default(true);

            $table->timestamps();

            $table->unique(
                ['tenant_id', 'id'],
                'skus_tenant_id_id_unique',
            );

            $table->unique(
                ['tenant_id', 'code'],
                'skus_tenant_code_unique',
            );

            $table->unique(
                ['tenant_id', 'barcode'],
                'skus_tenant_barcode_unique',
            );

            // A SKU can never reference a product
            // belonging to another tenant.
            $table->foreign(
                ['tenant_id', 'product_id'],
                'skus_tenant_product_foreign',
            )->references(
                ['tenant_id', 'id']
            )->on(
                'products'
            )->cascadeOnDelete();

            $table->index(
                [
                    'tenant_id',
                    'product_id',
                    'is_active',
                ],
                'skus_tenant_product_active_index',
            );
        });

        $this->enableTenantRls();
    }

    public function down(): void
    {
        Schema::dropIfExists('skus');
        Schema::dropIfExists('products');
    }

    private function enableTenantRls(): void
    {
        if (
            DB::connection()->getDriverName()
            !== 'pgsql'
        ) {
            return;
        }

        foreach (self::RLS_TABLES as $table) {
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
};
