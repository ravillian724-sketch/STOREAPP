<?php

namespace Tests\Feature;

use App\Models\AppInstance;
use App\Models\Branch;
use App\Models\Tenant;
use App\Services\AppInstanceCredentialService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformApiTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $tokens = [];

    private function token(string $alias): string
    {
        return $this->tokens[$alias];
    }

    private function createTenant(string $key = 'test-instance-key'): Tenant
    {
        $tenant = Tenant::query()->create([
            'name_ar' => 'متجر الاختبار',
            'name_en' => 'Test Store',
            'country_code' => 'SA',
            'currency_code' => 'SAR',
            'vat_rate' => 15,
            'primary_color' => '#0F766E',
            'secondary_color' => '#0F172A',
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

        $this->tokens[$key] = $issued->token;

        return $tenant;
    }

    private function inTenant(
        Tenant $tenant,
        callable $callback,
    ): mixed {
        $context = app(TenantContext::class);
        $previous = $context->id();
        $context->set($tenant->id);

        try {
            return $callback();
        } finally {
            if ($previous === null) {
                $context->clear();
            } else {
                $context->set($previous);
            }
        }
    }

    public function test_health_endpoint_is_available(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response
            ->assertOk()
            ->assertHeader('X-Request-Id')
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.version', 'v1');
    }

    public function test_bootstrap_resolves_tenant_from_app_instance_key(): void
    {
        $tenant = $this->createTenant();

        $branch = $this->inTenant(
            $tenant,
            fn (): Branch => Branch::query()->create([
                'code' => 'MAIN',
                'name_ar' => 'الرئيسي',
                'name_en' => 'Main',
                'is_active' => true,
            ]),
        );

        $response = $this
            ->withHeader(
                'X-App-Instance-Key',
                $this->token('test-instance-key'),
            )
            ->postJson('/api/v1/bootstrap', [
                'channel' => 'mobile',
            ]);

        $response
            ->assertOk()
            ->assertHeader('X-Request-Id')
            ->assertJsonPath('data.store.tenant_id', (string) $tenant->id)
            ->assertJsonPath('data.store.name_en', 'Test Store')
            ->assertJsonPath('data.default_branch_id', (string) $branch->id);

        $payload = json_decode($response->getContent());
        $this->assertIsObject($payload->data->store->features);
    }

    public function test_bootstrap_ignores_inactive_branch_when_selecting_default(): void
    {
        $tenant = $this->createTenant();

        $this->inTenant(
            $tenant,
            function (): void {
                Branch::query()->create([
                    'code' => 'CLOSED',
                    'name_ar' => 'مغلق',
                    'name_en' => 'Closed',
                    'is_active' => false,
                ]);
            },
        );

        $this->withHeader(
            'X-App-Instance-Key',
            $this->token('test-instance-key'),
        )->postJson('/api/v1/bootstrap')
            ->assertOk()
            ->assertJsonPath('data.default_branch_id', null);
    }

    public function test_invalid_app_instance_is_rejected(): void
    {
        $response = $this
            ->withHeader(
                'X-App-Instance-Key',
                'invalid-key',
            )
            ->postJson('/api/v1/bootstrap');

        $response
            ->assertNotFound()
            ->assertJsonPath(
                'error.code',
                'APP_INSTANCE_NOT_FOUND'
            );
    }

    public function test_app_instance_key_in_request_body_is_rejected(): void
    {
        $this->createTenant();

        $this
            ->postJson('/api/v1/bootstrap', [
                'app_instance_key' => $this->token('test-instance-key'),
            ])
            ->assertBadRequest()
            ->assertJsonPath(
                'error.code',
                'APP_INSTANCE_KEY_REQUIRED',
            );
    }

    public function test_tenant_header_cannot_override_resolved_tenant(): void
    {
        $this->createTenant();

        $response = $this
            ->withHeaders([
                'X-Tenant-Id' => '999999',
                'X-App-Instance-Key' => $this->token('test-instance-key'),
            ])
            ->postJson('/api/v1/bootstrap');

        $response
            ->assertForbidden()
            ->assertJsonPath(
                'error.code',
                'TENANT_CONTEXT_MISMATCH'
            );
    }
}
