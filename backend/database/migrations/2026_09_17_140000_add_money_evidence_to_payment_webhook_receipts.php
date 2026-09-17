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
                    ->unsignedBigInteger(
                        'amount_minor'
                    )
                    ->nullable()
                    ->after(
                        'event_type'
                    );

                $table
                    ->char(
                        'currency_code',
                        3,
                    )
                    ->nullable()
                    ->after(
                        'amount_minor'
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

    public function down(): void
    {
        Schema::table(
            'payment_webhook_receipts',
            function (Blueprint $table): void {
                $table->dropColumn([
                    'amount_minor',
                    'currency_code',
                ]);
            },
        );
    }
};
