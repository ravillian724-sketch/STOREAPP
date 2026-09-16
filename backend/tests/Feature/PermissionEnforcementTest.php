<?php

namespace Tests\Feature;

use App\Models\AppInstance;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AppInstanceCredentialService;
use App\Support\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PermissionEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware([
            'app.instance',
            'tenant.boundary',
            'tenant.staff',
            'permission:roles.view',
        ])->get(
            '/api/v1/test-permission',
            fn (Request $request) => ApiResponse::success(
                $request,
                ['authorized' => true],
            ),
        );
    }

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
            'tenant' => $tenant,
            'instance_token' => $issued->token,
        ];
    }

    private function staff(Tenant $tenant): User
    {
        $context = app(TenantContext::class);
        $context->set($tenant->id);

        try {
            return User::query()->create([
                'name' => 'Staff',
                'email' => 'staff@example.com',
                'password' => 'Secret123!',
                'is_active' => true,
            ]);
        } finally {
            $context->clear();
        }
    }

    private function grant(
        Tenant $tenant,
        User $user,
        bool $roleActive = true,
    ): void {
        $permission = Permission::query()->firstOrCreate(
            ['code' => 'roles.view'],
            ['name' => 'View roles'],
        );

        $context = app(TenantContext::class);
        $context->set($tenant->id);

        try {
            $role = Role::query()->create([
                'code' => 'manager',
                'name' => 'Manager',
                'is_active' => $roleActive,
            ]);

            $role->permissions()->attach(
                $permission->id
            );

            $user->assignRole($role);
        } finally {
            $context->clear();
        }
    }

    private function login(
        string $instanceToken,
    ): string {
        $response = $this
            ->withHeader(
                'X-App-Instance-Key',
                $instanceToken,
            )
            ->postJson(
                '/api/v1/staff/auth/login',
                [
                    'email' => 'staff@example.com',
                    'password' => 'Secret123!',
                    'device_name' => 'phpunit',
                ],
            );

        $response->assertOk();

        return (string) $response->json(
            'data.access_token'
        );
    }

    private function headers(
        string $instanceToken,
        string $accessToken,
    ): array {
        return [
            'X-App-Instance-Key' => $instanceToken,
            'Authorization' => 'Bearer '.$accessToken,
        ];
    }

    public function test_unauthenticated_request_is_denied(): void
    {
        $store = $this->store('Tenant A');

        $this
            ->withHeader(
                'X-App-Instance-Key',
                $store['instance_token'],
            )
            ->getJson('/api/v1/test-permission')
            ->assertUnauthorized()
            ->assertJsonPath(
                'error.code',
                'UNAUTHENTICATED',
            );
    }

    public function test_authenticated_user_without_permission_is_forbidden(): void
    {
        $store = $this->store('Tenant A');

        $this->staff($store['tenant']);

        $token = $this->login(
            $store['instance_token']
        );

        $this
            ->withHeaders(
                $this->headers(
                    $store['instance_token'],
                    $token,
                )
            )
            ->getJson('/api/v1/test-permission')
            ->assertForbidden()
            ->assertJsonPath(
                'error.code',
                'FORBIDDEN',
            );
    }

    public function test_user_with_permission_is_allowed(): void
    {
        $store = $this->store('Tenant A');

        $user = $this->staff(
            $store['tenant']
        );

        $this->grant(
            $store['tenant'],
            $user,
        );

        $token = $this->login(
            $store['instance_token']
        );

        $this
            ->withHeaders(
                $this->headers(
                    $store['instance_token'],
                    $token,
                )
            )
            ->getJson('/api/v1/test-permission')
            ->assertOk()
            ->assertJsonPath(
                'data.authorized',
                true,
            );
    }

    public function test_inactive_role_does_not_grant_permission(): void
    {
        $store = $this->store('Tenant A');

        $user = $this->staff(
            $store['tenant']
        );

        $this->grant(
            $store['tenant'],
            $user,
            false,
        );

        $token = $this->login(
            $store['instance_token']
        );

        $this
            ->withHeaders(
                $this->headers(
                    $store['instance_token'],
                    $token,
                )
            )
            ->getJson('/api/v1/test-permission')
            ->assertForbidden()
            ->assertJsonPath(
                'error.code',
                'FORBIDDEN',
            );
    }
}
