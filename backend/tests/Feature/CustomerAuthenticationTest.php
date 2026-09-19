<?php

namespace Tests\Feature;

use App\Models\AppInstance;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AppInstanceCredentialService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerAuthenticationTest extends TestCase
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

    private function inTenant(
        Tenant $tenant,
        callable $callback,
    ): mixed {
        $context = app(TenantContext::class);
        $context->set($tenant->id);

        try {
            return $callback();
        } finally {
            $context->clear();
        }
    }

    private function register(
        array $store,
        string $email = 'buyer@example.com',
        string $password = 'Buyer1234',
    ): array {
        $response = $this
            ->withHeader(
                'X-App-Instance-Key',
                $store['instance_token'],
            )
            ->postJson(
                '/api/v1/storefront/customer/auth/register',
                [
                    'name' => 'Buyer One',
                    'email' => $email,
                    'phone' => '+966500000001',
                    'password' => $password,
                    'device_name' => 'phpunit',
                ],
            );

        $response
            ->assertCreated()
            ->assertHeader(
                'Cache-Control',
                'no-store, private',
            );

        return [
            'token' => (string) $response->json(
                'data.access_token'
            ),
            'customer_id' => (string) $response->json(
                'data.customer.id'
            ),
        ];
    }

    private function authHeaders(
        array $store,
        string $token,
    ): array {
        return [
            'X-App-Instance-Key' => $store['instance_token'],
            'Authorization' => 'Bearer '.$token,
        ];
    }

    public function test_customer_can_register_and_read_own_profile(): void
    {
        $store = $this->createStore(
            'Tenant A'
        );

        $registered =
            $this->register($store);

        $this
            ->withHeaders(
                $this->authHeaders(
                    $store,
                    $registered['token'],
                )
            )
            ->getJson(
                '/api/v1/storefront/customer/me'
            )
            ->assertOk()
            ->assertHeader(
                'Cache-Control',
                'no-store, private',
            )
            ->assertJsonPath(
                'data.customer.id',
                $registered['customer_id'],
            )
            ->assertJsonPath(
                'data.customer.email',
                'buyer@example.com',
            )
            ->assertJsonPath(
                'data.customer.phone',
                '+966500000001',
            );

        $this->inTenant(
            $store['tenant'],
            function (): void {
                $customer =
                    Customer::query()
                        ->where(
                            'email',
                            'buyer@example.com',
                        )
                        ->firstOrFail();

                $this->assertNotSame(
                    'Buyer1234',
                    $customer->password,
                );
            }
        );
    }

    public function test_duplicate_email_is_rejected_inside_same_tenant(): void
    {
        $store = $this->createStore(
            'Tenant A'
        );

        $this->register(
            $store,
            'buyer@example.com',
        );

        $this
            ->withHeader(
                'X-App-Instance-Key',
                $store['instance_token'],
            )
            ->postJson(
                '/api/v1/storefront/customer/auth/register',
                [
                    'name' => 'Duplicate Buyer',
                    'email' => 'BUYER@example.com',
                    'password' => 'Other1234',
                ],
            )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'CUSTOMER_ALREADY_EXISTS',
            );
    }

    public function test_same_email_can_exist_in_different_tenants(): void
    {
        $storeA = $this->createStore(
            'Tenant A'
        );

        $storeB = $this->createStore(
            'Tenant B'
        );

        $buyerA = $this->register(
            $storeA,
            'same@example.com',
            'BuyerA123',
        );

        $buyerB = $this->register(
            $storeB,
            'same@example.com',
            'BuyerB123',
        );

        $this->assertNotSame(
            $buyerA['customer_id'],
            $buyerB['customer_id'],
        );
    }

    public function test_customer_can_login_case_insensitively_and_logout(): void
    {
        $store = $this->createStore(
            'Tenant A'
        );

        $this->register(
            $store,
            'buyer@example.com',
            'Buyer1234',
        );

        $response = $this
            ->withHeader(
                'X-App-Instance-Key',
                $store['instance_token'],
            )
            ->postJson(
                '/api/v1/storefront/customer/auth/login',
                [
                    'email' => 'BUYER@example.com',
                    'password' => 'Buyer1234',
                    'device_name' => 'second-device',
                ],
            )
            ->assertOk()
            ->assertHeader(
                'Cache-Control',
                'no-store, private',
            )
            ->assertJsonPath(
                'data.customer.email',
                'buyer@example.com',
            );

        $token =
            (string) $response->json(
                'data.access_token'
            );

        $headers =
            $this->authHeaders(
                $store,
                $token,
            );

        $this
            ->withHeaders($headers)
            ->postJson(
                '/api/v1/storefront/customer/auth/logout'
            )
            ->assertOk()
            ->assertHeader(
                'Cache-Control',
                'no-store, private',
            )
            ->assertJsonPath(
                'data.logged_out',
                true,
            );

        $this
            ->withHeaders($headers)
            ->getJson(
                '/api/v1/storefront/customer/me'
            )
            ->assertUnauthorized()
            ->assertJsonPath(
                'error.code',
                'UNAUTHENTICATED',
            );
    }

    public function test_wrong_password_is_rejected(): void
    {
        $store = $this->createStore(
            'Tenant A'
        );

        $this->register(
            $store,
            'buyer@example.com',
            'Buyer1234',
        );

        $this
            ->withHeader(
                'X-App-Instance-Key',
                $store['instance_token'],
            )
            ->postJson(
                '/api/v1/storefront/customer/auth/login',
                [
                    'email' => 'buyer@example.com',
                    'password' => 'wrong-password',
                ],
            )
            ->assertUnauthorized()
            ->assertJsonPath(
                'error.code',
                'INVALID_CREDENTIALS',
            );
    }

    public function test_customer_token_cannot_cross_tenant_boundary(): void
    {
        $storeA = $this->createStore(
            'Tenant A'
        );

        $storeB = $this->createStore(
            'Tenant B'
        );

        $buyer =
            $this->register(
                $storeA,
                'buyer@example.com',
            );

        $this
            ->withHeaders([
                'X-App-Instance-Key' => $storeB['instance_token'],
                'Authorization' => 'Bearer '.$buyer['token'],
            ])
            ->getJson(
                '/api/v1/storefront/customer/me'
            )
            ->assertUnauthorized()
            ->assertJsonPath(
                'error.code',
                'UNAUTHENTICATED',
            );
    }

    public function test_staff_token_is_rejected_by_customer_endpoints(): void
    {
        $store = $this->createStore(
            'Tenant A'
        );

        $staff = $this->inTenant(
            $store['tenant'],
            fn (): User => User::query()->create([
                'name' => 'Staff User',
                'email' => 'staff@example.com',
                'password' => 'Secret123!',
                'is_active' => true,
            ]),
        );

        $token = $staff
            ->createToken(
                'phpunit',
                ['staff'],
            )
            ->plainTextToken;

        $this
            ->withHeaders(
                $this->authHeaders(
                    $store,
                    $token,
                )
            )
            ->getJson(
                '/api/v1/storefront/customer/me'
            )
            ->assertUnauthorized()
            ->assertJsonPath(
                'error.code',
                'UNAUTHENTICATED',
            );
    }
}
