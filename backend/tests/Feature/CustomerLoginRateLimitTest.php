<?php

namespace Tests\Feature;

use App\Models\AppInstance;
use App\Models\Tenant;
use App\Services\AppInstanceCredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CustomerLoginRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function store(string $name): array
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
            'instance_token' => $issued->token,
        ];
    }

    private function badLogin(
        string $instanceToken,
        string $ip = '127.0.0.1',
    ): TestResponse {
        return $this
            ->withServerVariables([
                'REMOTE_ADDR' => $ip,
            ])
            ->withHeader(
                'X-App-Instance-Key',
                $instanceToken,
            )
            ->postJson(
                '/api/v1/storefront/customer/auth/login',
                [
                    'email' => 'buyer@example.com',
                    'password' => 'wrong-password',
                    'device_name' => 'phpunit',
                ],
            );
    }

    public function test_sixth_attempt_for_same_identity_is_rate_limited(): void
    {
        $store = $this->store(
            'Tenant A'
        );

        for (
            $attempt = 1;
            $attempt <= 5;
            $attempt++
        ) {
            $this
                ->badLogin(
                    $store['instance_token']
                )
                ->assertUnauthorized()
                ->assertJsonPath(
                    'error.code',
                    'INVALID_CREDENTIALS',
                );
        }

        $response = $this->badLogin(
            $store['instance_token']
        );

        $response
            ->assertStatus(429)
            ->assertJsonPath(
                'error.code',
                'TOO_MANY_LOGIN_ATTEMPTS',
            );

        $this->assertNotNull(
            $response->headers->get(
                'Retry-After'
            )
        );
    }

    public function test_customer_login_rate_limit_is_isolated_between_tenants(): void
    {
        $storeA = $this->store(
            'Tenant A'
        );

        $storeB = $this->store(
            'Tenant B'
        );

        for (
            $attempt = 1;
            $attempt <= 5;
            $attempt++
        ) {
            $this
                ->badLogin(
                    $storeA['instance_token']
                )
                ->assertUnauthorized();
        }

        $this
            ->badLogin(
                $storeA['instance_token']
            )
            ->assertStatus(429);

        $this
            ->badLogin(
                $storeB['instance_token']
            )
            ->assertUnauthorized()
            ->assertJsonPath(
                'error.code',
                'INVALID_CREDENTIALS',
            );
    }
}
