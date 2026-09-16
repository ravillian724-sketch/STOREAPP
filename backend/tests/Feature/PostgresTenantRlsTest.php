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

    public function test_runtime_role_cannot_bypass_rls(): void
    {
        $this->requirePostgres();

        $row = DB::selectOne(
            <<<'SQL'
            SELECT
                current_user AS username,
                CASE
                    WHEN rolsuper THEN 1
                    ELSE 0
                END AS is_superuser,
                CASE
                    WHEN rolbypassrls THEN 1
                    ELSE 0
                END AS can_bypass_rls
            FROM pg_roles
            WHERE rolname = current_user
            SQL
        );

        $this->assertNotNull($row);

        $this->assertSame(
            (string) config(
                'database.connections.pgsql.username'
            ),
            (string) $row->username,
        );

        $this->assertSame(
            0,
            (int) $row->is_superuser,
        );

        $this->assertSame(
            0,
            (int) $row->can_bypass_rls,
        );
    }

    public function test_without_global_scopes_cannot_bypass_database_rls(): void
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

        $context->set(
            $tenantA->id
        );

        try {
            $this->assertSame(
                ['A01'],
                Branch::withoutGlobalScopes()
                    ->orderBy('code')
                    ->pluck('code')
                    ->all(),
            );
        } finally {
            $context->clear();
        }
    }

    public function test_database_blocks_cross_tenant_update(): void
    {
        $this->requirePostgres();

        $tenantA = $this->tenant(
            'Tenant A'
        );

        $tenantB = $this->tenant(
            'Tenant B'
        );

        $branch = $this->branch(
            $tenantA,
            'A01',
        );

        $context = app(
            TenantContext::class
        );

        $context->set(
            $tenantA->id
        );

        $blocked = false;

        try {
            try {
                DB::transaction(
                    function () use (
                        $branch,
                        $tenantB,
                    ): void {
                        DB::table(
                            'branches'
                        )
                            ->where(
                                'id',
                                $branch->id,
                            )
                            ->update([
                                'tenant_id' => $tenantB->id,
                            ]);
                    }
                );
            } catch (QueryException) {
                $blocked = true;
            }
        } finally {
            $context->clear();
        }

        $this->assertTrue(
            $blocked,
            'PostgreSQL RLS allowed a cross-tenant update.',
        );
    }

    public function test_aborted_transaction_does_not_leak_or_mask_tenant_context(): void
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

        $caught = null;

        try {
            DB::transaction(
                function () use (
                    $context,
                    $tenantA,
                    $tenantB,
                ): void {
                    $context->set(
                        $tenantA->id
                    );

                    try {
                        DB::table(
                            'branches'
                        )->insert([
                            'tenant_id' => $tenantB->id,

                            'code' => 'ROLLBACK-TEST',

                            'name_ar' => 'ROLLBACK-TEST',

                            'name_en' => 'ROLLBACK-TEST',

                            'is_active' => true,

                            'created_at' => now(),

                            'updated_at' => now(),
                        ]);
                    } finally {
                        $context->clear();
                    }
                }
            );
        } catch (QueryException $exception) {
            $caught = $exception;
        }

        $this->assertNotNull(
            $caught
        );

        $sqlState = (string) (
            $caught->errorInfo[0]
            ?? $caught->getCode()
        );

        $this->assertNotSame(
            '25P02',
            $sqlState,
            'Tenant cleanup masked the original PostgreSQL error.',
        );

        $this->assertNull(
            $context->id()
        );

        $row = DB::selectOne(
            <<<'SQL'
            SELECT current_setting(
                'storeapp.tenant_id',
                true
            ) AS tenant_id
            SQL
        );

        $this->assertTrue(
            $row->tenant_id === null ||
            $row->tenant_id === '',
            'Tenant database context leaked after rollback.',
        );

        $this->assertSame(
            0,
            DB::table(
                'branches'
            )->count(),
        );
    }
}
