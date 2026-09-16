<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class AuditLogFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function requirePostgres(): void
    {
        if (
            DB::connection()->getDriverName()
            !== 'pgsql'
        ) {
            $this->markTestSkipped(
                'PostgreSQL-specific audit test.'
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

    private function inTenant(
        Tenant $tenant,
        callable $callback,
    ): mixed {
        $context = app(
            TenantContext::class
        );

        $context->set(
            $tenant->id
        );

        try {
            return $callback();
        } finally {
            $context->clear();
        }
    }

    private function staff(
        Tenant $tenant,
        string $email,
    ): User {
        return $this->inTenant(
            $tenant,
            fn (): User => User::query()->create([
                'name' => $email,
                'email' => $email,
                'password' => 'Password123!',
                'is_active' => true,
            ]),
        );
    }

    public function test_audit_logs_fail_closed_without_tenant_context(): void
    {
        $tenant = $this->tenant(
            'Tenant A'
        );

        $this->inTenant(
            $tenant,
            function (): void {
                app(AuditLogger::class)
                    ->record(
                        'catalog.product.created',
                        'product',
                        '100',
                    );
            },
        );

        $this->assertSame(
            0,
            AuditLog::query()->count(),
        );
    }

    public function test_audit_logger_records_actor_and_request_context(): void
    {
        $tenant = $this->tenant(
            'Tenant A'
        );

        $actor = $this->staff(
            $tenant,
            'owner@example.com',
        );

        $request = Request::create(
            '/api/v1/admin/catalog/products',
            'POST',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => '127.0.0.1',
            ],
        );

        $request->attributes->set(
            'request_id',
            'audit-request-001',
        );

        $log = $this->inTenant(
            $tenant,
            fn (): AuditLog => app(AuditLogger::class)
                ->record(
                    action: 'catalog.product.created',
                    subjectType: 'product',
                    subjectId: '123',
                    actor: $actor,
                    before: null,
                    after: [
                        'name_en' => 'Test Product',
                    ],
                    metadata: [
                        'source' => 'admin_api',
                    ],
                    request: $request,
                ),
        );

        $this->assertSame(
            $tenant->id,
            $log->tenant_id,
        );

        $this->assertSame(
            $actor->id,
            $log->actor_user_id,
        );

        $this->assertSame(
            'catalog.product.created',
            $log->action,
        );

        $this->assertSame(
            '123',
            $log->subject_id,
        );

        $this->assertSame(
            'audit-request-001',
            $log->request_id,
        );

        $this->assertSame(
            'POST',
            $log->http_method,
        );

        $this->assertSame(
            [
                'name_en' => 'Test Product',
            ],
            $log->after_values,
        );
    }

    public function test_cross_tenant_actor_is_rejected(): void
    {
        $tenantA = $this->tenant(
            'Tenant A'
        );

        $tenantB = $this->tenant(
            'Tenant B'
        );

        $foreignActor = $this->staff(
            $tenantB,
            'foreign@example.com',
        );

        $blocked = false;

        try {
            $this->inTenant(
                $tenantA,
                function () use (
                    $foreignActor
                ): void {
                    app(AuditLogger::class)
                        ->record(
                            'staff.updated',
                            'user',
                            $foreignActor->id,
                            $foreignActor,
                        );
                },
            );
        } catch (LogicException) {
            $blocked = true;
        }

        $this->assertTrue(
            $blocked
        );
    }

    public function test_audit_log_is_immutable_through_eloquent(): void
    {
        $tenant = $this->tenant(
            'Tenant A'
        );

        $log = $this->inTenant(
            $tenant,
            fn (): AuditLog => app(AuditLogger::class)
                ->record(
                    'role.created',
                    'role',
                    '10',
                ),
        );

        $updateBlocked = false;
        $deleteBlocked = false;

        $this->inTenant(
            $tenant,
            function () use (
                $log,
                &$updateBlocked,
                &$deleteBlocked,
            ): void {
                try {
                    $log->action =
                        'tampered';

                    $log->save();
                } catch (LogicException) {
                    $updateBlocked = true;
                }

                try {
                    $log->delete();
                } catch (LogicException) {
                    $deleteBlocked = true;
                }
            },
        );

        $this->assertTrue(
            $updateBlocked
        );

        $this->assertTrue(
            $deleteBlocked
        );
    }

    public function test_postgres_audit_log_is_rls_protected_and_database_immutable(): void
    {
        $this->requirePostgres();

        $tenantA = $this->tenant(
            'Tenant A'
        );

        $tenantB = $this->tenant(
            'Tenant B'
        );

        $logA = $this->inTenant(
            $tenantA,
            fn (): AuditLog => app(AuditLogger::class)
                ->record(
                    'tenant.a.event',
                    'test',
                    'A',
                ),
        );

        $this->inTenant(
            $tenantB,
            fn (): AuditLog => app(AuditLogger::class)
                ->record(
                    'tenant.b.event',
                    'test',
                    'B',
                ),
        );

        $context = app(
            TenantContext::class
        );

        $context->set(
            $tenantA->id
        );

        try {
            $this->assertSame(
                ['tenant.a.event'],
                DB::table('audit_logs')
                    ->orderBy('id')
                    ->pluck('action')
                    ->all(),
            );

            $updateBlocked = false;

            try {
                DB::transaction(
                    fn () => DB::table('audit_logs')
                        ->where(
                            'id',
                            $logA->id,
                        )
                        ->update([
                            'action' => 'tampered',
                        ])
                );
            } catch (QueryException) {
                $updateBlocked = true;
            }

            $this->assertTrue(
                $updateBlocked
            );

            $deleteBlocked = false;

            try {
                DB::transaction(
                    fn () => DB::table('audit_logs')
                        ->where(
                            'id',
                            $logA->id,
                        )
                        ->delete()
                );
            } catch (QueryException) {
                $deleteBlocked = true;
            }

            $this->assertTrue(
                $deleteBlocked
            );
        } finally {
            $context->clear();
        }
    }

    public function test_audit_logger_redacts_sensitive_values(): void
    {
        $tenant = $this->tenant(
            'Tenant A'
        );

        $log = $this->inTenant(
            $tenant,
            fn (): AuditLog => app(AuditLogger::class)
                ->record(
                    action: 'security.test',
                    subjectType: 'test',
                    subjectId: '1',
                    before: [
                        'password' => 'DoNotStoreThis',
                    ],
                    after: [
                        'access_token' => 'secret-token',
                    ],
                    metadata: [
                        'safe_value' => 'visible',
                        'nested' => [
                            'api_key' => 'secret-key',
                        ],
                    ],
                ),
        );

        $this->assertSame(
            '[REDACTED]',
            $log->before_values['password'],
        );

        $this->assertSame(
            '[REDACTED]',
            $log->after_values['access_token'],
        );

        $this->assertSame(
            '[REDACTED]',
            $log->metadata['nested']['api_key'],
        );

        $this->assertSame(
            'visible',
            $log->metadata['safe_value'],
        );
    }
}
