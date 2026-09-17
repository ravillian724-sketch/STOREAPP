<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (
            DB::connection()->getDriverName()
            !== 'pgsql'
        ) {
            return;
        }

        /*
         * PostgreSQL CHECK constraints accept TRUE or NULL.
         *
         * The original expression could evaluate to NULL
         * when amount_minor was present but currency_code
         * was NULL:
         *
         *     currency_code ~ regex => NULL
         *
         * Therefore the malformed pair was not rejected.
         *
         * Explicit IS NOT NULL predicates force every
         * malformed partial pair to evaluate to FALSE.
         */
        DB::statement(
            <<<'SQL'
            ALTER TABLE payment_webhook_receipts
            DROP CONSTRAINT IF EXISTS
            payment_webhook_receipts_money_pair
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE payment_webhook_receipts
            ADD CONSTRAINT
            payment_webhook_receipts_money_pair
            CHECK (
                (
                    amount_minor IS NULL
                    AND
                    currency_code IS NULL
                )
                OR
                (
                    amount_minor IS NOT NULL
                    AND
                    currency_code IS NOT NULL
                    AND
                    amount_minor > 0
                    AND
                    currency_code ~ '^[A-Z]{3}$'
                )
            )
            SQL
        );
    }

    public function down(): void
    {
        if (
            DB::connection()->getDriverName()
            !== 'pgsql'
        ) {
            return;
        }

        DB::statement(
            <<<'SQL'
            ALTER TABLE payment_webhook_receipts
            DROP CONSTRAINT IF EXISTS
            payment_webhook_receipts_money_pair
            SQL
        );

        /*
         * Restore the exact schema state produced by the
         * preceding migration.
         */
        DB::statement(
            <<<'SQL'
            ALTER TABLE payment_webhook_receipts
            ADD CONSTRAINT
            payment_webhook_receipts_money_pair
            CHECK (
                (
                    amount_minor IS NULL
                    AND currency_code IS NULL
                )
                OR
                (
                    amount_minor IS NOT NULL
                    AND amount_minor > 0
                    AND currency_code ~ '^[A-Z]{3}$'
                )
            )
            SQL
        );
    }
};
