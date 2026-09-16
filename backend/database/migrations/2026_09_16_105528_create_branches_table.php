<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('code', 64);
            $table->string('name_ar');
            $table->string('name_en');
            $table->boolean('is_active')
                ->default(true)
                ->index();

            $table->timestamps();

            $table->index(
                ['tenant_id', 'is_active', 'id'],
                'branches_tenant_active_id_idx',
            );

            $table->unique([
                'tenant_id',
                'code',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
