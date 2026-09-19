<?php

namespace Tests\Feature;

use App\Models\AppInstanceCredential;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\StoreProvisioningReceipt;
use App\Models\Tenant;
use App\Models\User;
use App\Support\AppInstance\AppInstanceToken;
use App\Support\Authorization\SystemRoleCatalog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ControlPlaneProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN =
        'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'control_plane.token' => self::TOKEN,
            'control_plane.rate_limit_per_minute' => 30,
        ]);
    }

    private function payload(): array
    {
        return [
            'tenant' => [
                'name_ar' => 'صيدلية الاختبار',
                'name_en' => 'Provisioned Pharmacy',
                'country_code' => 'sa',
                'currency_code' => 'sar',
                'vat_rate' => 15,
                'primary_color' => '#0f766e',
                'secondary_color' => '#0f172a',
            ],
            'branch' => [
                'code' => 'main',
                'name_ar' => 'الفرع الرئيسي',
                'name_en' => 'Main Branch',
            ],
            'app_instance' => [
                'channel' => 'mobile',
            ],
            'owner' => [
                'name' => 'Initial Owner',
                'email' => 'OWNER@EXAMPLE.COM',
                'password' => 'OwnerPassword123',
            ],
        ];
    }

    private function headers(
        string $idempotencyKey =
            'provision-store-000001',
        string $token = self::TOKEN,
    ): array {
        return [
            'Authorization' => 'Bearer '.$token,
            'Idempotency-Key' => $idempotencyKey,
        ];
    }

    private function inTenant(
        Tenant $tenant,
        callable $callback,
    ): mixed {
        return DB::transaction(
            function () use (
                $tenant,
                $callback,
            ): mixed {
                $context = app(
                    TenantContext::class
                );

                $context->set(
                    (int) $tenant->id
                );

                try {
                    return $callback();
                } finally {
                    $context->clear();
                }
            }
        );
    }

    public function test_control_plane_fails_closed_when_not_configured(): void
    {
        config([
            'control_plane.token' => null,
        ]);

        $this
            ->withHeaders(
                $this->headers()
            )
            ->postJson(
                '/api/v1/control-plane/stores',
                $this->payload(),
            )
            ->assertStatus(503)
            ->assertJsonPath(
                'error.code',
                'CONTROL_PLANE_UNAVAILABLE',
            );

        $this->assertSame(
            0,
            Tenant::query()->count(),
        );
    }

    public function test_invalid_control_plane_credential_is_rejected(): void
    {
        $this
            ->withHeaders(
                $this->headers(
                    token: str_repeat('x', 64),
                )
            )
            ->postJson(
                '/api/v1/control-plane/stores',
                $this->payload(),
            )
            ->assertUnauthorized()
            ->assertJsonPath(
                'error.code',
                'UNAUTHENTICATED',
            );

        $this->assertSame(
            0,
            Tenant::query()->count(),
        );
    }

    public function test_provisioning_atomically_creates_store_owner_and_one_time_credential(): void
    {
        $response = $this
            ->withHeaders(
                $this->headers()
            )
            ->postJson(
                '/api/v1/control-plane/stores',
                $this->payload(),
            )
            ->assertCreated()
            ->assertHeader(
                'Cache-Control',
                'no-store, private',
            )
            ->assertJsonPath(
                'data.provisioning.status',
                'completed',
            )
            ->assertJsonPath(
                'data.provisioning.replayed',
                false,
            )
            ->assertJsonPath(
                'data.branch.code',
                'MAIN',
            )
            ->assertJsonPath(
                'data.tenant.country_code',
                'SA',
            )
            ->assertJsonPath(
                'data.tenant.currency_code',
                'SAR',
            )
            ->assertJsonPath(
                'data.owner.email',
                'owner@example.com',
            )
            ->assertJsonPath(
                'data.credential.reissue_required',
                false,
            );

        $token = (string) $response->json(
            'data.credential.app_instance_key'
        );

        $this->assertStringStartsWith(
            'si1.',
            $token,
        );

        $this->assertSame(
            1,
            Tenant::query()->count(),
        );

        $this->assertSame(
            1,
            StoreProvisioningReceipt::query()
                ->count(),
        );

        $credential =
            AppInstanceCredential::query()
                ->sole();

        $parsed = app(
            AppInstanceToken::class
        )->parse($token);

        $this->assertNotNull($parsed);

        $this->assertTrue(
            app(AppInstanceToken::class)
                ->verify(
                    $parsed['secret'],
                    $credential->secret_hash,
                )
        );

        $this->assertStringNotContainsString(
            $token,
            $credential->secret_hash,
        );

        $tenant = Tenant::query()->sole();

        $this->inTenant(
            $tenant,
            function (): void {
                $branch = Branch::query()
                    ->sole();

                $this->assertSame(
                    'MAIN',
                    $branch->code,
                );

                $owner = User::query()
                    ->sole();

                $this->assertSame(
                    'owner@example.com',
                    $owner->email,
                );

                $this->assertTrue(
                    $owner
                        ->roles()
                        ->where(
                            'roles.code',
                            SystemRoleCatalog::OWNER,
                        )
                        ->exists()
                );

                $this->assertSame(
                    1,
                    AuditLog::query()
                        ->where(
                            'action',
                            'tenant.provisioned',
                        )
                        ->count(),
                );
            }
        );
    }

    public function test_replay_returns_same_store_without_revealing_credential_again(): void
    {
        $first = $this
            ->withHeaders(
                $this->headers()
            )
            ->postJson(
                '/api/v1/control-plane/stores',
                $this->payload(),
            )
            ->assertCreated();

        $firstTenant =
            $first->json(
                'data.tenant.id'
            );

        $second = $this
            ->withHeaders(
                $this->headers()
            )
            ->postJson(
                '/api/v1/control-plane/stores',
                $this->payload(),
            )
            ->assertOk()
            ->assertJsonPath(
                'data.provisioning.replayed',
                true,
            )
            ->assertJsonPath(
                'data.tenant.id',
                $firstTenant,
            )
            ->assertJsonPath(
                'data.credential.app_instance_key',
                null,
            )
            ->assertJsonPath(
                'data.credential.reissue_required',
                true,
            );

        $this->assertSame(
            1,
            Tenant::query()->count(),
        );

        $this->assertSame(
            1,
            AppInstanceCredential::query()
                ->count(),
        );

        $this->assertSame(
            $first->json(
                'data.provisioning.id'
            ),
            $second->json(
                'data.provisioning.id'
            ),
        );
    }

    public function test_reusing_idempotency_key_with_changed_data_is_rejected(): void
    {
        $this
            ->withHeaders(
                $this->headers()
            )
            ->postJson(
                '/api/v1/control-plane/stores',
                $this->payload(),
            )
            ->assertCreated();

        $changed = $this->payload();
        $changed['tenant']['name_en'] =
            'Different Pharmacy';

        $this
            ->withHeaders(
                $this->headers()
            )
            ->postJson(
                '/api/v1/control-plane/stores',
                $changed,
            )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'IDEMPOTENCY_CONFLICT',
            );

        $this->assertSame(
            1,
            Tenant::query()->count(),
        );
    }

    public function test_provisioning_rolls_back_everything_when_audit_write_fails(): void
    {
        $driver =
            DB::connection()
                ->getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared(
                <<<'SQL'
                CREATE TRIGGER
                control_plane_test_fail_audit
                BEFORE INSERT ON audit_logs
                BEGIN
                    SELECT RAISE(
                        ABORT,
                        'forced provisioning audit failure'
                    );
                END
                SQL
            );
        } elseif ($driver === 'pgsql') {
            DB::unprepared(
                <<<'SQL'
                CREATE OR REPLACE FUNCTION
                control_plane_test_fail_audit()
                RETURNS trigger
                LANGUAGE plpgsql
                AS $$
                BEGIN
                    RAISE EXCEPTION
                        'forced provisioning audit failure';
                END;
                $$;

                CREATE TRIGGER
                control_plane_test_fail_audit
                BEFORE INSERT ON audit_logs
                FOR EACH ROW
                EXECUTE FUNCTION
                control_plane_test_fail_audit();
                SQL
            );
        }

        try {
            $this
                ->withHeaders(
                    $this->headers(
                        'provision-store-rollback-01'
                    )
                )
                ->postJson(
                    '/api/v1/control-plane/stores',
                    $this->payload(),
                )
                ->assertStatus(500);
        } finally {
            if ($driver === 'sqlite') {
                DB::unprepared(
                    'DROP TRIGGER IF EXISTS control_plane_test_fail_audit'
                );
            } elseif ($driver === 'pgsql') {
                DB::unprepared(
                    <<<'SQL'
                    DROP TRIGGER IF EXISTS
                    control_plane_test_fail_audit
                    ON audit_logs;

                    DROP FUNCTION IF EXISTS
                    control_plane_test_fail_audit();
                    SQL
                );
            }
        }

        $this->assertSame(
            0,
            Tenant::query()->count(),
        );

        $this->assertSame(
            0,
            StoreProvisioningReceipt::query()
                ->count(),
        );

        $this->assertSame(
            0,
            AppInstanceCredential::query()
                ->count(),
        );
    }

    public function test_rate_limit_cannot_be_bypassed_by_changing_invalid_tokens(): void
    {
        config([
            'control_plane.rate_limit_per_minute' => 2,
        ]);

        for (
            $attempt = 1;
            $attempt <= 2;
            $attempt++
        ) {
            $this
                ->withServerVariables([
                    'REMOTE_ADDR' => '198.51.100.77',
                ])
                ->withHeaders([
                    'Authorization' => 'Bearer '.
                        str_repeat(
                            (string) $attempt,
                            64,
                        ),
                    'Idempotency-Key' => 'rate-limit-test-0001',
                ])
                ->postJson(
                    '/api/v1/control-plane/stores',
                    $this->payload(),
                )
                ->assertUnauthorized();
        }

        $this
            ->withServerVariables([
                'REMOTE_ADDR' => '198.51.100.77',
            ])
            ->withHeaders([
                'Authorization' => 'Bearer '.
                    str_repeat('9', 64),
                'Idempotency-Key' => 'rate-limit-test-0001',
            ])
            ->postJson(
                '/api/v1/control-plane/stores',
                $this->payload(),
            )
            ->assertStatus(429)
            ->assertJsonPath(
                'error.code',
                'CONTROL_PLANE_RATE_LIMITED',
            );
    }

    public function test_sqlite_receipt_rejects_cross_tenant_branch_link(): void
    {
        if (
            DB::connection()->getDriverName()
            !== 'sqlite'
        ) {
            $this->markTestSkipped(
                'SQLite-specific provisioning coherence test.'
            );
        }

        $this
            ->withHeaders(
                $this->headers(
                    'provision-store-coherence-01'
                )
            )
            ->postJson(
                '/api/v1/control-plane/stores',
                $this->payload(),
            )
            ->assertCreated();

        $receipt =
            StoreProvisioningReceipt::query()
                ->sole();

        $originalBranchId =
            $receipt->branch_id;

        $otherTenant = Tenant::query()->create([
            'name_ar' => 'صيدلية أخرى',
            'name_en' => 'Other Pharmacy',
            'country_code' => 'SA',
            'currency_code' => 'SAR',
            'vat_rate' => 15,
            'primary_color' => '#111111',
            'secondary_color' => '#EEEEEE',
            'is_active' => true,
        ]);

        $otherBranch = $this->inTenant(
            $otherTenant,
            fn (): Branch => Branch::query()->create([
                'code' => 'OTHER',
                'name_ar' => 'فرع آخر',
                'name_en' => 'Other Branch',
                'is_active' => true,
            ]),
        );

        try {
            DB::table(
                'store_provisioning_receipts'
            )
                ->where(
                    'id',
                    $receipt->id,
                )
                ->update([
                    'branch_id' => $otherBranch->id,
                ]);

            $this->fail(
                'Cross-tenant branch link was accepted.'
            );
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $this->assertSame(
            $originalBranchId,
            StoreProvisioningReceipt::query()
                ->findOrFail(
                    $receipt->id
                )
                ->branch_id,
        );
    }

    public function test_postgres_receipt_has_coherence_constraints(): void
    {
        if (
            DB::connection()->getDriverName()
            !== 'pgsql'
        ) {
            $this->markTestSkipped(
                'PostgreSQL-specific provisioning coherence test.'
            );
        }

        $names = DB::table('pg_constraint')
            ->whereIn(
                'conname',
                [
                    'app_instance_credentials_instance_id_unique',
                    'store_provisioning_receipts_tenant_branch_foreign',
                    'store_provisioning_receipts_tenant_app_instance_foreign',
                    'store_provisioning_receipts_tenant_owner_foreign',
                    'store_provisioning_receipts_instance_credential_foreign',
                    'store_provisioning_receipts_completed_links_present',
                ],
            )
            ->orderBy('conname')
            ->pluck('conname')
            ->all();

        $this->assertSame(
            [
                'app_instance_credentials_instance_id_unique',
                'store_provisioning_receipts_completed_links_present',
                'store_provisioning_receipts_instance_credential_foreign',
                'store_provisioning_receipts_tenant_app_instance_foreign',
                'store_provisioning_receipts_tenant_branch_foreign',
                'store_provisioning_receipts_tenant_owner_foreign',
            ],
            $names,
        );
    }
}
