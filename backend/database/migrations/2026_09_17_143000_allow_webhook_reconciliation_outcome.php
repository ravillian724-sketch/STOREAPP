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

        DB::statement(
            <<<'SQL'
            ALTER TABLE payment_webhook_receipts
            DROP CONSTRAINT IF EXISTS
            payment_webhook_receipts_outcome_valid
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE payment_webhook_receipts
            ADD CONSTRAINT
            payment_webhook_receipts_outcome_valid
            CHECK (
                processing_outcome IS NULL
                OR
                processing_outcome IN (
                    'applied',
                    'ignored_terminal',
                    'requires_reconciliation'
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
            payment_webhook_receipts_outcome_valid
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE payment_webhook_receipts
            ADD CONSTRAINT
            payment_webhook_receipts_outcome_valid
            CHECK (
                processing_outcome IS NULL
                OR
                processing_outcome IN (
                    'applied',
                    'ignored_terminal'
                )
            )
            SQL
        );
    }
};
