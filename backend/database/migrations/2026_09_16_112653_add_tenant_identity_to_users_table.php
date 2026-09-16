<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_unique');
        });

        Schema::table('users', function (Blueprint $table) {
            $table
                ->foreignId('tenant_id')
                ->constrained()
                ->cascadeOnDelete();

            $table
                ->boolean('is_active')
                ->default(true)
                ->index();

            $table
                ->timestamp('last_login_at')
                ->nullable();

            $table->unique(
                ['tenant_id', 'email'],
                'users_tenant_email_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(
                'users_tenant_email_unique'
            );

            $table->dropConstrainedForeignId(
                'tenant_id'
            );

            $table->dropColumn([
                'is_active',
                'last_login_at',
            ]);

            $table->unique('email');
        });
    }
};
