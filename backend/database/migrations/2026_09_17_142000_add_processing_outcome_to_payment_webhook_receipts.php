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
            'payment_webhook_receipts',
            function (Blueprint $table): void {
                $table
                    ->string(
                        'processing_outcome',
                        32,
                    )
                    ->nullable()
                    ->after(
                        'processed_at'
                    );

                $table->index(
                    [
                        'tenant_id',
                        'processing_outcome',
                        'received_at',
                    ],
                    'payment_webhook_receipts_outcome_index',
                );
            },
        );

        if (
            DB::connection()->getDriverName()
            !== 'pgsql'
        ) {
            return;
        }

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

        /*
         * Forward-compatible with receipts processed before
         * this column existed:
         *
         * legacy processed_at + NULL outcome remains valid.
         *
         * But from this migration onward an outcome can
         * never exist without processed_at.
         */
        DB::statement(
            <<<'SQL'
            ALTER TABLE payment_webhook_receipts
            ADD CONSTRAINT
            payment_webhook_receipts_outcome_requires_processed
            CHECK (
                processing_outcome IS NULL
                OR
                processed_at IS NOT NULL
            )
            SQL
        );
    }

    public function down(): void
    {
        if (
            DB::connection()->getDriverName()
            === 'pgsql'
        ) {
            DB::statement(
                <<<'SQL'
                ALTER TABLE payment_webhook_receipts
                DROP CONSTRAINT IF EXISTS
                payment_webhook_receipts_outcome_requires_processed
                SQL
            );

            DB::statement(
                <<<'SQL'
                ALTER TABLE payment_webhook_receipts
                DROP CONSTRAINT IF EXISTS
                payment_webhook_receipts_outcome_valid
                SQL
            );
        }

        Schema::table(
            'payment_webhook_receipts',
            function (Blueprint $table): void {
                $table->dropIndex(
                    'payment_webhook_receipts_outcome_index'
                );

                $table->dropColumn(
                    'processing_outcome'
                );
            },
        );
    }
};
