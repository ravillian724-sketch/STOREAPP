<?php

namespace Tests\Feature;

use App\Models\AppInstance;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AppInstanceCredentialService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function createStore(string $name): array
    {
        $tenant = Tenant::query()->create([
            'name_ar' => $name,
            'name_en' => $name,
            'country_code' => 'SA',
            'currency_code' => 'SAR',
            'vat_rate' => 15,
            'primary_color' => '#000000',
            'secondary_color' => '#FFFFFF',
            'is_active' => true,
        ]);

        $instance = AppInstance::query()->create([
            'tenant_id' => $tenant->id,
            'channel' => 'mobile',
            'is_active' => true,
        ]);

        $issued = app(
            AppInstanceCredentialService::class
        )->issue($instance);

        return [
            'tenant' => $tenant,
            'instance_token' => $issued->token,
        ];
    }

    private function createStaff(
        Tenant $tenant,
        string $email,
        string $password = 'Secret123!',
    ): User {
        $context = app(TenantContext::class);
        $context->set($tenant->id);

        try {
            return User::query()->create([
                'name' => 'Staff User',
                'email' => $email,
                'password' => $password,
                'is_active' => true,
            ]);
        } finally {
            $context->clear();
        }
    }

    private function login(
        string $instanceToken,
        string $email,
        string $password,
    ): string {
        $response = $this
            ->withHeader(
                'X-App-Instance-Key',
                $instanceToken,
            )
            ->postJson(
                '/api/v1/staff/auth/login',
                [
                    'email' => $email,
                    'password' => $password,
                    'device_name' => 'phpunit',
                ],
            );

        $response->assertOk();

        return (string) $response->json(
            'data.access_token'
        );
    }

    public function test_staff_can_login_inside_own_tenant(): void
    {
        $store = $this->createStore('Tenant A');

        $this->createStaff(
            $store['tenant'],
            'staff@example.com',
        );

        $this
            ->withHeader(
                'X-App-Instance-Key',
                $store['instance_token'],
            )
            ->postJson(
                '/api/v1/staff/auth/login',
                [
                    'email' => 'STAFF@example.com',
                    'password' => 'Secret123!',
                ],
            )
            ->assertOk()
            ->assertJsonPath(
                'data.user.tenant_id',
                (string) $store['tenant']->id,
            );
    }

    public function test_wrong_password_is_rejected(): void
    {
        $store = $this->createStore('Tenant A');

        $this->createStaff(
            $store['tenant'],
            'staff@example.com',
        );

        $this
            ->withHeader(
                'X-App-Instance-Key',
                $store['instance_token'],
            )
            ->postJson(
                '/api/v1/staff/auth/login',
                [
                    'email' => 'staff@example.com',
                    'password' => 'wrong-password',
                ],
            )
            ->assertUnauthorized()
            ->assertJsonPath(
                'error.code',
                'INVALID_CREDENTIALS',
            );
    }

    public function test_same_email_is_resolved_inside_correct_tenant(): void
    {
        $storeA = $this->createStore('Tenant A');
        $storeB = $this->createStore('Tenant B');

        $this->createStaff(
            $storeA['tenant'],
            'same@example.com',
            'Password-A',
        );

        $userB = $this->createStaff(
            $storeB['tenant'],
            'same@example.com',
            'Password-B',
        );

        $token = $this->login(
            $storeB['instance_token'],
            'same@example.com',
            'Password-B',
        );

        $this
            ->withHeaders([
                'X-App-Instance-Key' => $storeB['instance_token'],
                'Authorization' => 'Bearer '.$token,
            ])
            ->getJson('/api/v1/staff/auth/me')
            ->assertOk()
            ->assertJsonPath(
                'data.user.id',
                (string) $userB->id,
            );
    }

    public function test_staff_token_cannot_cross_tenant_boundary(): void
    {
        $storeA = $this->createStore('Tenant A');
        $storeB = $this->createStore('Tenant B');

        $this->createStaff(
            $storeA['tenant'],
            'staff@example.com',
        );

        $token = $this->login(
            $storeA['instance_token'],
            'staff@example.com',
            'Secret123!',
        );

        $this
            ->withHeaders([
                'X-App-Instance-Key' => $storeB['instance_token'],
                'Authorization' => 'Bearer '.$token,
            ])
            ->getJson('/api/v1/staff/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath(
                'error.code',
                'UNAUTHENTICATED',
            );
    }

    public function test_logout_revokes_current_token(): void
    {
        $store = $this->createStore('Tenant A');

        $this->createStaff(
            $store['tenant'],
            'staff@example.com',
        );

        $token = $this->login(
            $store['instance_token'],
            'staff@example.com',
            'Secret123!',
        );

        $headers = [
            'X-App-Instance-Key' => $store['instance_token'],
            'Authorization' => 'Bearer '.$token,
        ];

        $this
            ->withHeaders($headers)
            ->postJson('/api/v1/staff/auth/logout')
            ->assertOk();

        $this
            ->withHeaders($headers)
            ->getJson('/api/v1/staff/auth/me')
            ->assertUnauthorized();
    }
}
