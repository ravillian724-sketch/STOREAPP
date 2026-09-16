<?php

namespace Tests\Feature;

use App\Models\AppInstance;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformApiTest extends TestCase
{
    use RefreshDatabase;

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

        AppInstance::query()->create([
            'tenant_id' => $tenant->id,
            'key_hash' => hash('sha256', $key),
            'channel' => 'mobile',
            'is_active' => true,
        ]);

        return $tenant;
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

        $response = $this->postJson('/api/v1/bootstrap', [
            'app_instance_key' => 'test-instance-key',
            'channel' => 'mobile',
        ]);

        $response
            ->assertOk()
            ->assertHeader('X-Request-Id')
            ->assertJsonPath('data.store.tenant_id', (string) $tenant->id)
            ->assertJsonPath('data.store.name_en', 'Test Store');
    }

    public function test_invalid_app_instance_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/bootstrap', [
            'app_instance_key' => 'invalid-key',
        ]);

        $response
            ->assertNotFound()
            ->assertJsonPath(
                'error.code',
                'APP_INSTANCE_NOT_FOUND'
            );
    }

    public function test_tenant_header_cannot_override_resolved_tenant(): void
    {
        $this->createTenant();

        $response = $this
            ->withHeader('X-Tenant-Id', '999999')
            ->postJson('/api/v1/bootstrap', [
                'app_instance_key' => 'test-instance-key',
            ]);

        $response
            ->assertForbidden()
            ->assertJsonPath(
                'error.code',
                'TENANT_CONTEXT_MISMATCH'
            );
    }
}
