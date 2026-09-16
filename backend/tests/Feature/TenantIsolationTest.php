<?php

namespace Tests\Feature;

use App\Models\AppInstance;
use App\Models\Branch;
use App\Models\Tenant;
use App\Services\AppInstanceCredentialService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $tokens = [];

    private function token(string $alias): string
    {
        return $this->tokens[$alias];
    }

    private function createTenant(
        string $name,
        string $key,
    ): Tenant {
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

        $this->tokens[$key] = $issued->token;

        return $tenant;
    }

    public function test_tenant_owned_models_fail_closed_without_context(): void
    {
        $tenant = $this->createTenant(
            'Tenant A',
            'tenant-a-key',
        );

        DB::table('branches')->insert([
            'tenant_id' => $tenant->id,
            'code' => 'A01',
            'name_ar' => 'فرع أ',
            'name_en' => 'Branch A',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(
            0,
            Branch::query()->count(),
        );
    }

    public function test_each_tenant_can_only_read_its_own_branches(): void
    {
        $tenantA = $this->createTenant(
            'Tenant A',
            'tenant-a-key',
        );

        $tenantB = $this->createTenant(
            'Tenant B',
            'tenant-b-key',
        );

        DB::table('branches')->insert([
            [
                'tenant_id' => $tenantA->id,
                'code' => 'A01',
                'name_ar' => 'فرع أ',
                'name_en' => 'Branch A',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => $tenantB->id,
                'code' => 'B01',
                'name_ar' => 'فرع ب',
                'name_en' => 'Branch B',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $responseA = $this
            ->withHeader(
                'X-App-Instance-Key',
                $this->token('tenant-a-key'),
            )
            ->getJson('/api/v1/branches');

        $responseA
            ->assertOk()
            ->assertJsonCount(
                1,
                'data.branches',
            )
            ->assertJsonPath(
                'data.branches.0.code',
                'A01',
            );

        $responseB = $this
            ->withHeader(
                'X-App-Instance-Key',
                $this->token('tenant-b-key'),
            )
            ->getJson('/api/v1/branches');

        $responseB
            ->assertOk()
            ->assertJsonCount(
                1,
                'data.branches',
            )
            ->assertJsonPath(
                'data.branches.0.code',
                'B01',
            );
    }

    public function test_forged_tenant_header_is_rejected(): void
    {
        $tenantA = $this->createTenant(
            'Tenant A',
            'tenant-a-key',
        );

        $tenantB = $this->createTenant(
            'Tenant B',
            'tenant-b-key',
        );

        $this
            ->withHeaders([
                'X-App-Instance-Key' => $this->token('tenant-a-key'),
                'X-Tenant-Id' => (string) $tenantB->id,
            ])
            ->getJson('/api/v1/branches')
            ->assertForbidden()
            ->assertJsonPath(
                'error.code',
                'TENANT_CONTEXT_MISMATCH',
            );

        $this->assertNotSame(
            $tenantA->id,
            $tenantB->id,
        );
    }

    public function test_tenant_context_is_cleared_after_request(): void
    {
        $tenant = $this->createTenant(
            'Tenant A',
            'tenant-a-key',
        );

        DB::table('branches')->insert([
            'tenant_id' => $tenant->id,
            'code' => 'A01',
            'name_ar' => 'فرع أ',
            'name_en' => 'Branch A',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this
            ->withHeader(
                'X-App-Instance-Key',
                $this->token('tenant-a-key'),
            )
            ->getJson('/api/v1/branches')
            ->assertOk();

        $this->assertNull(
            app(TenantContext::class)->id(),
        );

        $this->assertSame(
            0,
            Branch::query()->count(),
        );
    }

    public function test_caller_cannot_assign_another_tenant_on_create(): void
    {
        $tenantA = $this->createTenant(
            'Tenant A',
            'tenant-a-key',
        );

        $tenantB = $this->createTenant(
            'Tenant B',
            'tenant-b-key',
        );

        $context = app(TenantContext::class);
        $context->set($tenantA->id);

        try {
            $branch = Branch::query()->create([
                'tenant_id' => $tenantB->id,
                'code' => 'SAFE01',
                'name_ar' => 'فرع آمن',
                'name_en' => 'Safe Branch',
                'is_active' => true,
            ]);

            $this->assertSame(
                $tenantA->id,
                $branch->tenant_id,
            );
        } finally {
            $context->clear();
        }
    }

    public function test_stale_context_is_cleared_before_rejected_request(): void
    {
        $tenant = $this->createTenant(
            'Tenant A',
            'tenant-a-key',
        );

        $context = app(TenantContext::class);

        // Simulate stale state in a long-running worker.
        $context->set($tenant->id);

        $this
            ->withHeader(
                'X-App-Instance-Key',
                'invalid-instance-key',
            )
            ->getJson('/api/v1/branches')
            ->assertNotFound();

        $this->assertNull(
            $context->id(),
        );
    }

    public function test_existing_record_cannot_change_tenant_ownership(): void
    {
        $tenantA = $this->createTenant(
            'Tenant A',
            'tenant-a-key',
        );

        $tenantB = $this->createTenant(
            'Tenant B',
            'tenant-b-key',
        );

        $context = app(TenantContext::class);
        $context->set($tenantA->id);

        try {
            $branch = Branch::query()->create([
                'code' => 'LOCKED01',
                'name_ar' => 'فرع مقفل',
                'name_en' => 'Locked Branch',
                'is_active' => true,
            ]);

            $branch->tenant_id = $tenantB->id;

            $this->expectException(
                LogicException::class,
            );

            $branch->save();
        } finally {
            $context->clear();
        }
    }
}
