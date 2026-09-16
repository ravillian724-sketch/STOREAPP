<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'app_instance_credentials',
            function (Blueprint $table) {
                $table->id();

                $table
                    ->foreignId('app_instance_id')
                    ->constrained()
                    ->cascadeOnDelete();

                $table
                    ->string('public_id', 26)
                    ->unique();

                $table->char(
                    'secret_hash',
                    64,
                );

                $table
                    ->timestamp('valid_from')
                    ->index();

                $table
                    ->timestamp('expires_at')
                    ->nullable()
                    ->index();

                $table
                    ->timestamp('revoked_at')
                    ->nullable()
                    ->index();

                $table->timestamps();

                $table->index(
                    [
                        'app_instance_id',
                        'revoked_at',
                    ],
                    'app_instance_credentials_instance_revoked_idx',
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'app_instance_credentials'
        );
    }
};
