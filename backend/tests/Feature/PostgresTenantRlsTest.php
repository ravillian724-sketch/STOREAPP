<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostgresTenantRlsTest extends TestCase
{
    use RefreshDatabase;

    private function requirePostgres(): void
    {
        if (
            DB::connection()->getDriverName()
            !== 'pgsql'
        ) {
            $this->markTestSkipped(
                'PostgreSQL-specific RLS test.'
            );
        }
    }

    private function tenant(
        string $name,
    ): Tenant {
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

    private function branch(
        Tenant $tenant,
        string $code,
    ): Branch {
        $context = app(
            TenantContext::class
        );

        $context->set($tenant->id);

        try {
            return Branch::query()->create([
                'code' => $code,
                'name_ar' => $code,
                'name_en' => $code,
                'is_active' => true,
            ]);
        } finally {
            $context->clear();
        }
    }

    public function test_required_tables_have_forced_rls(): void
    {
        $this->requirePostgres();

        $rows = collect(
            DB::select(
                <<<'SQL'
                SELECT
                    c.relname,
                    c.relrowsecurity,
                    c.relforcerowsecurity
                FROM pg_class c
                WHERE c.relname IN (
                    'branches',
                    'users',
                    'roles',
                    'role_user',
                    'products',
                    'skus',
                    'inventory_locations',
                    'stock_ledger_entries'
                )
                ORDER BY c.relname
                SQL
            )
        )->keyBy('relname');

        $this->assertSame(
            [
                'branches',
                'inventory_locations',
                'products',
                'role_user',
                'roles',
                'skus',
                'stock_ledger_entries',
                'users',
            ],
            $rows->keys()->all(),
        );

        foreach ($rows as $row) {
            $this->assertTrue(
                (bool) $row->relrowsecurity
            );

            $this->assertTrue(
                (bool) $row->relforcerowsecurity
            );
        }
    }

    public function test_raw_queries_are_isolated_by_database(): void
    {
        $this->requirePostgres();

        $tenantA = $this->tenant(
            'Tenant A'
        );

        $tenantB = $this->tenant(
            'Tenant B'
        );

        $this->branch(
            $tenantA,
            'A01',
        );

        $this->branch(
            $tenantB,
            'B01',
        );

        $context = app(
            TenantContext::class
        );

        $context->set($tenantA->id);

        try {
            $this->assertSame(
                ['A01'],
                DB::table('branches')
                    ->orderBy('code')
                    ->pluck('code')
                    ->all(),
            );
        } finally {
            $context->clear();
        }

        $this->assertSame(
            0,
            DB::table('branches')->count(),
        );
    }

    public function test_database_blocks_cross_tenant_insert(): void
    {
        $this->requirePostgres();

        $tenantA = $this->tenant(
            'Tenant A'
        );

        $tenantB = $this->tenant(
            'Tenant B'
        );

        $context = app(
            TenantContext::class
        );

        $context->set($tenantA->id);

        $blocked = false;

        try {
            try {
                DB::transaction(
                    function () use (
                        $tenantB
                    ): void {
                        DB::table(
                            'branches'
                        )->insert([
                            'tenant_id' => $tenantB->id,

                            'code' => 'ILLEGAL',

                            'name_ar' => 'ILLEGAL',

                            'name_en' => 'ILLEGAL',

                            'is_active' => true,

                            'created_at' => now(),

                            'updated_at' => now(),
                        ]);
                    }
                );
            } catch (QueryException) {
                $blocked = true;
            }

            $this->assertTrue(
                $blocked,
                'PostgreSQL RLS did not block a cross-tenant insert.',
            );
        } finally {
            $context->clear();
        }
    }
}
