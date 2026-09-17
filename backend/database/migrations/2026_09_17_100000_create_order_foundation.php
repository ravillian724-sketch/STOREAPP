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
            'orders',
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

                $table->uuid(
                    'public_id'
                )->unique();

                $table->string(
                    'status',
                    32,
                )->default('pending');

                /*
                 * ISO-style currency snapshot.
                 *
                 * The Order must never depend on the
                 * tenant's future currency configuration.
                 */
                $table->char(
                    'currency_code',
                    3,
                );

                /*
                 * Historical customer/contact snapshots.
                 *
                 * There is intentionally no customer_id
                 * yet because Customer identity does not
                 * exist in the current domain.
                 */
                $table->string(
                    'customer_name',
                    200,
                )->nullable();

                $table->string(
                    'customer_phone',
                    50,
                )->nullable();

                $table->string(
                    'customer_email',
                    254,
                )->nullable();

                $table->json(
                    'shipping_address_snapshot'
                )->nullable();

                /*
                 * Money is stored in integer minor units.
                 *
                 * SAR 10.50 = 1050.
                 *
                 * No binary floating-point money enters
                 * the order ledger.
                 */
                $table->unsignedBigInteger(
                    'subtotal_minor'
                )->default(0);

                $table->unsignedBigInteger(
                    'discount_minor'
                )->default(0);

                $table->unsignedBigInteger(
                    'tax_minor'
                )->default(0);

                /*
                 * Gross delivery/shipping amount.
                 * Shipping tax allocation can be added
                 * by the future quote layer.
                 */
                $table->unsignedBigInteger(
                    'shipping_minor'
                )->default(0);

                $table->unsignedBigInteger(
                    'total_minor'
                )->default(0);

                $table->timestampTz(
                    'confirmed_at'
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
                    'orders_tenant_id_id_unique',
                );

                /*
                 * A Cart can materialize into at most
                 * one Order inside a tenant.
                 */
                $table->unique(
                    [
                        'tenant_id',
                        'cart_id',
                    ],
                    'orders_tenant_cart_unique',
                );

                $table->foreign(
                    [
                        'tenant_id',
                        'app_instance_id',
                    ],
                    'orders_tenant_app_instance_foreign',
                )
                    ->references([
                        'tenant_id',
                        'id',
                    ])
                    ->on(
                        'app_instances'
                    )
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'tenant_id',
                        'cart_id',
                    ],
                    'orders_tenant_cart_foreign',
                )
                    ->references([
                        'tenant_id',
                        'id',
                    ])
                    ->on('carts')
                    ->restrictOnDelete();

                $table->index(
                    [
                        'tenant_id',
                        'status',
                        'created_at',
                    ],
                    'orders_status_lookup_index',
                );
            },
        );

        Schema::create(
            'order_items',
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

                $table->unsignedBigInteger(
                    'sku_id'
                );

                $table->unsignedBigInteger(
                    'location_id'
                );

                $table->uuid(
                    'public_id'
                )->unique();

                /*
                 * Catalog identity snapshots.
                 *
                 * Orders must remain readable even if
                 * catalog labels change later.
                 */
                $table->string(
                    'sku_code_snapshot',
                    100,
                );

                $table->string(
                    'barcode_snapshot',
                    100,
                )->nullable();

                $table->string(
                    'product_name_ar_snapshot'
                );

                $table->string(
                    'product_name_en_snapshot'
                );

                $table->string(
                    'sku_name_ar_snapshot'
                )->nullable();

                $table->string(
                    'sku_name_en_snapshot'
                )->nullable();

                $table->unsignedBigInteger(
                    'quantity'
                );

                /*
                 * Canonical tax-exclusive line values.
                 *
                 * The future pricing layer is responsible
                 * for converting any VAT-inclusive display
                 * price into these exact snapshots.
                 */
                $table->unsignedBigInteger(
                    'unit_net_minor'
                );

                $table->unsignedBigInteger(
                    'line_subtotal_minor'
                );

                $table->unsignedBigInteger(
                    'discount_minor'
                )->default(0);

                /*
                 * 1500 basis points = 15.00%.
                 */
                $table->unsignedInteger(
                    'tax_rate_bps'
                );

                $table->unsignedBigInteger(
                    'tax_minor'
                )->default(0);

                $table->unsignedBigInteger(
                    'line_total_minor'
                );

                $table->timestampsTz();

                $table->unique(
                    [
                        'tenant_id',
                        'id',
                    ],
                    'order_items_tenant_id_id_unique',
                );

                $table->foreign(
                    [
                        'tenant_id',
                        'order_id',
                    ],
                    'order_items_tenant_order_foreign',
                )
                    ->references([
                        'tenant_id',
                        'id',
                    ])
                    ->on('orders')
                    ->cascadeOnDelete();

                $table->foreign(
                    [
                        'tenant_id',
                        'sku_id',
                    ],
                    'order_items_tenant_sku_foreign',
                )
                    ->references([
                        'tenant_id',
                        'id',
                    ])
                    ->on('skus')
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'tenant_id',
                        'location_id',
                    ],
                    'order_items_tenant_location_foreign',
                )
                    ->references([
                        'tenant_id',
                        'id',
                    ])
                    ->on(
                        'inventory_locations'
                    )
                    ->restrictOnDelete();

                $table->index(
                    [
                        'tenant_id',
                        'order_id',
                        'id',
                    ],
                    'order_items_order_index',
                );
            },
        );

        $this->enablePostgresProtection();
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'order_items'
        );

        Schema::dropIfExists(
            'orders'
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
            ALTER TABLE orders
            ADD CONSTRAINT
            orders_status_valid
            CHECK (
                status IN (
                    'pending',
                    'confirmed',
                    'cancelled'
                )
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE orders
            ADD CONSTRAINT
            orders_status_timestamps_consistent
            CHECK (
                (
                    status = 'pending'
                    AND confirmed_at IS NULL
                    AND cancelled_at IS NULL
                )
                OR
                (
                    status = 'confirmed'
                    AND confirmed_at IS NOT NULL
                    AND cancelled_at IS NULL
                )
                OR
                (
                    status = 'cancelled'
                    AND cancelled_at IS NOT NULL
                )
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE orders
            ADD CONSTRAINT
            orders_currency_code_valid
            CHECK (
                currency_code ~ '^[A-Z]{3}$'
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE orders
            ADD CONSTRAINT
            orders_amounts_consistent
            CHECK (
                subtotal_minor >= 0
                AND discount_minor >= 0
                AND tax_minor >= 0
                AND shipping_minor >= 0
                AND total_minor >= 0
                AND subtotal_minor >= discount_minor
                AND total_minor =
                    subtotal_minor
                    - discount_minor
                    + tax_minor
                    + shipping_minor
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE order_items
            ADD CONSTRAINT
            order_items_quantity_positive
            CHECK (quantity > 0)
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE order_items
            ADD CONSTRAINT
            order_items_tax_rate_valid
            CHECK (
                tax_rate_bps >= 0
                AND tax_rate_bps <= 10000
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE order_items
            ADD CONSTRAINT
            order_items_amounts_consistent
            CHECK (
                unit_net_minor >= 0
                AND line_subtotal_minor >= 0
                AND discount_minor >= 0
                AND tax_minor >= 0
                AND line_total_minor >= 0
                AND line_subtotal_minor >=
                    discount_minor
                AND line_total_minor =
                    line_subtotal_minor
                    - discount_minor
                    + tax_minor
            )
            SQL
        );

        foreach (
            [
                'orders',
                'order_items',
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
