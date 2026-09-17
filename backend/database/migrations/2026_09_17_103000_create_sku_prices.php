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
            'sku_prices',
            function (Blueprint $table): void {
                $table->id();

                $table->foreignId(
                    'tenant_id'
                )
                    ->constrained()
                    ->cascadeOnDelete();

                $table->unsignedBigInteger(
                    'sku_id'
                );

                $table->uuid(
                    'public_id'
                )->unique();

                /*
                 * Snapshot of the tenant currency when
                 * this price version is created.
                 */
                $table->char(
                    'currency_code',
                    3,
                );

                /*
                 * Integer minor units only.
                 *
                 * 25.75 SAR = 2575.
                 *
                 * No float or decimal money is stored.
                 */
                $table->unsignedBigInteger(
                    'amount_minor'
                );

                /*
                 * 1500 basis points = 15.00%.
                 *
                 * This is explicit per price version
                 * because products can have different
                 * tax treatment.
                 */
                $table->unsignedInteger(
                    'tax_rate_bps'
                );

                /*
                 * The trusted catalog price may be
                 * tax-inclusive or tax-exclusive.
                 *
                 * 8C1C-B will normalize it into exact
                 * net/tax/gross quote amounts.
                 */
                $table->boolean(
                    'tax_inclusive'
                );

                /*
                 * Half-open validity interval:
                 *
                 * [effective_from, effective_until)
                 *
                 * null effective_until = no scheduled end.
                 */
                $table->timestampTz(
                    'effective_from'
                );

                $table->timestampTz(
                    'effective_until'
                )->nullable();

                $table->boolean(
                    'is_active'
                )->default(true);

                $table->timestampsTz();

                $table->unique(
                    [
                        'tenant_id',
                        'id',
                    ],
                    'sku_prices_tenant_id_id_unique',
                );

                /*
                 * A price can reference only a SKU owned
                 * by the same tenant.
                 */
                $table->foreign(
                    [
                        'tenant_id',
                        'sku_id',
                    ],
                    'sku_prices_tenant_sku_foreign',
                )
                    ->references([
                        'tenant_id',
                        'id',
                    ])
                    ->on('skus')
                    ->restrictOnDelete();

                $table->index(
                    [
                        'tenant_id',
                        'sku_id',
                        'currency_code',
                        'is_active',
                        'effective_from',
                    ],
                    'sku_prices_resolution_index',
                );
            },
        );

        $this->enablePostgresProtection();
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'sku_prices'
        );

        /*
         * Do not drop btree_gist here.
         * Other application objects may use the same
         * shared PostgreSQL extension later.
         */
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
            ALTER TABLE sku_prices
            ADD CONSTRAINT
            sku_prices_currency_code_valid
            CHECK (
                currency_code ~ '^[A-Z]{3}$'
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE sku_prices
            ADD CONSTRAINT
            sku_prices_amount_positive
            CHECK (
                amount_minor > 0
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE sku_prices
            ADD CONSTRAINT
            sku_prices_tax_rate_valid
            CHECK (
                tax_rate_bps >= 0
                AND tax_rate_bps <= 10000
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE sku_prices
            ADD CONSTRAINT
            sku_prices_window_valid
            CHECK (
                effective_until IS NULL
                OR effective_until > effective_from
            )
            SQL
        );

        /*
         * btree_gist lets PostgreSQL combine equality
         * dimensions with a timestamp-range exclusion
         * constraint.
         *
         * This prevents concurrent writers from creating
         * two active overlapping prices for the same
         * tenant/SKU/currency.
         */
        DB::statement(
            'CREATE EXTENSION IF NOT EXISTS btree_gist'
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE sku_prices
            ADD CONSTRAINT
            sku_prices_no_active_overlap
            EXCLUDE USING gist (
                tenant_id WITH =,
                sku_id WITH =,
                currency_code WITH =,
                tstzrange(
                    effective_from,
                    effective_until,
                    '[)'
                ) WITH &&
            )
            WHERE (is_active)
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE sku_prices
            ENABLE ROW LEVEL SECURITY
            SQL
        );

        DB::statement(
            <<<'SQL'
            CREATE POLICY tenant_isolation_policy
            ON sku_prices
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
            ALTER TABLE sku_prices
            FORCE ROW LEVEL SECURITY
            SQL
        );
    }
};
