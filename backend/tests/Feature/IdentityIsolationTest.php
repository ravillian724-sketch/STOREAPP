<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class IdentityIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $name): Tenant
    {
        return Tenant::query()->create([
            'name_ar' => $name,
            'name_en' => $name,
            'country_code' => 'SA',
            'currency_code' => 'SAR',
            'vat_rate' => 15,
            'primary_color' => '#000000',
            'secondary_color' => '#FFFFFF',
            'is_active' => true,
        ]);
    }

    public function test_users_fail_closed_without_tenant_context(): void
    {
        $this->assertSame(
            0,
            User::query()->count(),
        );
    }

    public function test_same_email_can_exist_independently_in_two_tenants(): void
    {
        $tenantA = $this->tenant('Tenant A');
        $tenantB = $this->tenant('Tenant B');

        $context = app(TenantContext::class);

        $context->set($tenantA->id);

        $userA = User::query()->create([
            'name' => 'User A',
            'email' => 'SAME@example.com',
            'password' => 'password-a',
        ]);

        $context->clear();
        $context->set($tenantB->id);

        $userB = User::query()->create([
            'name' => 'User B',
            'email' => 'same@example.com',
            'password' => 'password-b',
        ]);

        $this->assertNotSame(
            $userA->id,
            $userB->id,
        );

        $this->assertSame(
            'same@example.com',
            $userA->email,
        );

        $this->assertTrue(
            Hash::check(
                'password-b',
                $userB->password,
            ),
        );

        $context->clear();
    }

    public function test_user_queries_cannot_cross_tenant_boundary(): void
    {
        $tenantA = $this->tenant('Tenant A');
        $tenantB = $this->tenant('Tenant B');

        $context = app(TenantContext::class);

        $context->set($tenantA->id);

        User::query()->create([
            'name' => 'A',
            'email' => 'a@example.com',
            'password' => 'password',
        ]);

        $context->clear();
        $context->set($tenantB->id);

        User::query()->create([
            'name' => 'B',
            'email' => 'b@example.com',
            'password' => 'password',
        ]);

        $this->assertSame(
            ['b@example.com'],
            User::query()
                ->pluck('email')
                ->all(),
        );

        $context->clear();
    }
}
