<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 120)->unique();
            $table->string('name', 160);
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();

            $table
                ->foreignId('tenant_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('code', 80);
            $table->string('name', 120);

            $table
                ->boolean('is_system')
                ->default(false);

            $table
                ->boolean('is_active')
                ->default(true)
                ->index();

            $table->timestamps();

            $table->unique(
                ['tenant_id', 'code'],
                'roles_tenant_code_unique',
            );

            $table->index(
                ['tenant_id', 'is_active'],
                'roles_tenant_active_index',
            );
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table
                ->foreignId('role_id')
                ->constrained('roles')
                ->cascadeOnDelete();

            $table
                ->foreignId('permission_id')
                ->constrained('permissions')
                ->cascadeOnDelete();

            $table->primary([
                'role_id',
                'permission_id',
            ]);
        });

        Schema::create('role_user', function (Blueprint $table) {
            $table
                ->foreignId('tenant_id')
                ->constrained()
                ->cascadeOnDelete();

            $table
                ->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table
                ->foreignId('role_id')
                ->constrained('roles')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique(
                [
                    'tenant_id',
                    'user_id',
                    'role_id',
                ],
                'role_user_tenant_unique',
            );

            $table->index(
                ['tenant_id', 'user_id'],
                'role_user_tenant_user_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
