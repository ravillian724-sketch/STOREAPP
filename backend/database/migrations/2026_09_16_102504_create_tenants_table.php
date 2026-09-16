<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar');
            $table->string('name_en');
            $table->char('country_code', 2)->default('SA');
            $table->char('currency_code', 3)->default('SAR');
            $table->decimal('vat_rate', 5, 2)->default(15);
            $table->string('primary_color', 20)->default('#0F766E');
            $table->string('secondary_color', 20)->default('#0F172A');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
