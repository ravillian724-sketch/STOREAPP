<?php

namespace Tests\Feature;

use App\Models\AppBuildProfile;
use App\Models\AuditLog;
use App\Models\StoreProvisioningReceipt;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ControlPlaneBuildProfileTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN =
        'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'control_plane.token' => self::TOKEN,
            'control_plane.rate_limit_per_minute' => 100,
        ]);
    }

    private function headers(
        ?string $idempotencyKey = null,
    ): array {
        $headers = [
            'Authorization' => 'Bearer '.self::TOKEN,
        ];

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] =
                $idempotencyKey;
        }

        return $headers;
    }

    private function provisioningPayload(
        string $suffix,
        string $channel = 'mobile',
    ): array {
        return [
            'tenant' => [
                'name_ar' => 'صيدلية '.$suffix,
                'name_en' => 'Pharmacy '.$suffix,
                'country_code' => 'SA',
                'currency_code' => 'SAR',
                'vat_rate' => 15,
                'primary_color' => '#0F766E',
                'secondary_color' => '#0F172A',
            ],
            'branch' => [
                'code' => 'MAIN',
                'name_ar' => 'الرئيسي',
                'name_en' => 'Main',
            ],
            'app_instance' => [
                'channel' => $channel,
            ],
            'owner' => [
                'name' => 'Owner '.$suffix,
                'email' => strtolower($suffix).
                    '@example.com',
                'password' => 'OwnerPassword123',
            ],
        ];
    }

    private function provision(
        string $suffix = 'Alpha',
        string $channel = 'mobile',
    ): string {
        $response = $this
            ->withHeaders(
                $this->headers(
                    'provision-build-'.
                    strtolower($suffix).
                    '-000001',
                )
            )
            ->postJson(
                '/api/v1/control-plane/stores',
                $this->provisioningPayload(
                    $suffix,
                    $channel,
                ),
            )
            ->assertCreated();

        return (string) $response->json(
            'data.provisioning.id'
        );
    }

    private function auditCount(
        string $provisioningId,
        string $action,
    ): int {
        $receipt = StoreProvisioningReceipt::query()
            ->where(
                'public_id',
                $provisioningId,
            )
            ->firstOrFail();

        $context = app(
            TenantContext::class
        );

        $context->set(
            (int) $receipt->tenant_id
        );

        try {
            return AuditLog::query()
                ->where(
                    'action',
                    $action,
                )
                ->count();
        } finally {
            $context->clear();
        }
    }

    private function profilePayload(
        string $slug = 'pharmacy-alpha',
        string $androidId = 'com.storeapp.alpha',
        string $iosId = 'com.storeapp.alpha',
        string $version = '1.0.0',
        int $build = 1,
    ): array {
        return [
            'slug' => $slug,
            'display_name' => 'Pharmacy Alpha',
            'android_application_id' => $androidId,
            'ios_bundle_id' => $iosId,
            'version_name' => $version,
            'build_number' => $build,
        ];
    }

    public function test_build_profile_can_be_configured_and_manifest_contains_no_secret(): void
    {
        $provisioningId =
            $this->provision();

        $this
            ->withHeaders($this->headers())
            ->putJson(
                '/api/v1/control-plane/provisioning/'.
                $provisioningId.
                '/build-profile',
                $this->profilePayload(),
            )
            ->assertCreated()
            ->assertHeader(
                'Cache-Control',
                'no-store, private',
            )
            ->assertJsonPath(
                'data.created',
                true,
            )
            ->assertJsonPath(
                'data.profile.slug',
                'pharmacy-alpha',
            )
            ->assertJsonPath(
                'data.profile.android_application_id',
                'com.storeapp.alpha',
            )
            ->assertJsonPath(
                'data.profile.ios_bundle_id',
                'com.storeapp.alpha',
            );

        $manifestResponse = $this
            ->withHeaders($this->headers())
            ->getJson(
                '/api/v1/control-plane/provisioning/'.
                $provisioningId.
                '/build-manifest'
            )
            ->assertOk()
            ->assertHeader(
                'Cache-Control',
                'no-store, private',
            )
            ->assertJsonPath(
                'data.manifest.schema_version',
                1,
            )
            ->assertJsonPath(
                'data.manifest.profile.slug',
                'pharmacy-alpha',
            )
            ->assertJsonPath(
                'data.manifest.profile.version_name',
                '1.0.0',
            )
            ->assertJsonPath(
                'data.manifest.profile.build_number',
                1,
            )
            ->assertJsonPath(
                'data.manifest.android.application_id',
                'com.storeapp.alpha',
            )
            ->assertJsonPath(
                'data.manifest.ios.bundle_id',
                'com.storeapp.alpha',
            )
            ->assertJsonPath(
                'data.manifest.runtime.app_instance_key_required',
                true,
            )
            ->assertJsonPath(
                'data.manifest.artifact_basename',
                'pharmacy-alpha-1.0.0+1',
            );

        $json = $manifestResponse
            ->getContent();

        $manifestResponse->assertJsonMissingPath(
            'data.manifest.runtime.app_instance_key'
        );

        $this->assertStringNotContainsString(
            'si1.',
            $json,
        );

        $this->assertSame(
            1,
            AppBuildProfile::query()->count(),
        );

        $this->assertSame(
            1,
            $this->auditCount(
                $provisioningId,
                'build_profile.created',
            ),
        );
    }

    public function test_mutable_build_metadata_can_be_updated_without_changing_identity(): void
    {
        $provisioningId =
            $this->provision();

        $this
            ->withHeaders($this->headers())
            ->putJson(
                '/api/v1/control-plane/provisioning/'.
                $provisioningId.
                '/build-profile',
                $this->profilePayload(),
            )
            ->assertCreated();

        $payload = $this->profilePayload(
            version: '1.1.0',
            build: 2,
        );

        $payload['display_name'] =
            'Pharmacy Alpha Plus';

        $this
            ->withHeaders($this->headers())
            ->putJson(
                '/api/v1/control-plane/provisioning/'.
                $provisioningId.
                '/build-profile',
                $payload,
            )
            ->assertOk()
            ->assertJsonPath(
                'data.created',
                false,
            )
            ->assertJsonPath(
                'data.profile.display_name',
                'Pharmacy Alpha Plus',
            )
            ->assertJsonPath(
                'data.profile.version_name',
                '1.1.0',
            )
            ->assertJsonPath(
                'data.profile.build_number',
                2,
            );

        $this->assertSame(
            1,
            AppBuildProfile::query()->count(),
        );

        $this->assertSame(
            1,
            $this->auditCount(
                $provisioningId,
                'build_profile.updated',
            ),
        );
    }

    public function test_build_identity_is_immutable_after_creation(): void
    {
        $provisioningId =
            $this->provision();

        $this
            ->withHeaders($this->headers())
            ->putJson(
                '/api/v1/control-plane/provisioning/'.
                $provisioningId.
                '/build-profile',
                $this->profilePayload(),
            )
            ->assertCreated();

        $this
            ->withHeaders($this->headers())
            ->putJson(
                '/api/v1/control-plane/provisioning/'.
                $provisioningId.
                '/build-profile',
                $this->profilePayload(
                    androidId: 'com.storeapp.changed',
                ),
            )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'BUILD_PROFILE_CONFLICT',
            );

        $this->assertSame(
            'com.storeapp.alpha',
            AppBuildProfile::query()
                ->firstOrFail()
                ->android_application_id,
        );
    }

    public function test_build_number_cannot_decrease(): void
    {
        $provisioningId =
            $this->provision();

        $this
            ->withHeaders($this->headers())
            ->putJson(
                '/api/v1/control-plane/provisioning/'.
                $provisioningId.
                '/build-profile',
                $this->profilePayload(
                    build: 5,
                ),
            )
            ->assertCreated();

        $this
            ->withHeaders($this->headers())
            ->putJson(
                '/api/v1/control-plane/provisioning/'.
                $provisioningId.
                '/build-profile',
                $this->profilePayload(
                    build: 4,
                ),
            )
            ->assertStatus(422)
            ->assertJsonPath(
                'error.code',
                'BUILD_PROFILE_INVALID',
            );
    }

    public function test_exact_profile_replay_can_keep_same_build_number(): void
    {
        $provisioningId =
            $this->provision();

        $payload = $this->profilePayload();

        $this
            ->withHeaders($this->headers())
            ->putJson(
                '/api/v1/control-plane/provisioning/'.
                $provisioningId.
                '/build-profile',
                $payload,
            )
            ->assertCreated();

        $this
            ->withHeaders($this->headers())
            ->putJson(
                '/api/v1/control-plane/provisioning/'.
                $provisioningId.
                '/build-profile',
                $payload,
            )
            ->assertOk()
            ->assertJsonPath(
                'data.profile.build_number',
                1,
            );

        $this->assertSame(
            0,
            $this->auditCount(
                $provisioningId,
                'build_profile.updated',
            ),
        );
    }

    public function test_changed_metadata_requires_higher_build_number(): void
    {
        $provisioningId =
            $this->provision();

        $this
            ->withHeaders($this->headers())
            ->putJson(
                '/api/v1/control-plane/provisioning/'.
                $provisioningId.
                '/build-profile',
                $this->profilePayload(),
            )
            ->assertCreated();

        $payload = $this->profilePayload(
            version: '1.1.0',
            build: 1,
        );

        $this
            ->withHeaders($this->headers())
            ->putJson(
                '/api/v1/control-plane/provisioning/'.
                $provisioningId.
                '/build-profile',
                $payload,
            )
            ->assertStatus(422)
            ->assertJsonPath(
                'error.code',
                'BUILD_PROFILE_INVALID',
            );
    }

    public function test_native_identifiers_are_unique_between_provisioned_stores(): void
    {
        $alpha =
            $this->provision('Alpha');

        $beta =
            $this->provision('Beta');

        $this
            ->withHeaders($this->headers())
            ->putJson(
                '/api/v1/control-plane/provisioning/'.
                $alpha.
                '/build-profile',
                $this->profilePayload(),
            )
            ->assertCreated();

        $this
            ->withHeaders($this->headers())
            ->putJson(
                '/api/v1/control-plane/provisioning/'.
                $beta.
                '/build-profile',
                $this->profilePayload(
                    slug: 'pharmacy-beta',
                    androidId: 'com.storeapp.alpha',
                    iosId: 'com.storeapp.beta',
                ),
            )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'BUILD_PROFILE_CONFLICT',
            );

        $this->assertSame(
            1,
            AppBuildProfile::query()->count(),
        );
    }

    public function test_mobile_profile_requires_both_native_identifiers(): void
    {
        $provisioningId =
            $this->provision();

        $payload = $this->profilePayload();
        $payload['ios_bundle_id'] = null;

        $this
            ->withHeaders($this->headers())
            ->putJson(
                '/api/v1/control-plane/provisioning/'.
                $provisioningId.
                '/build-profile',
                $payload,
            )
            ->assertStatus(422)
            ->assertJsonPath(
                'error.code',
                'BUILD_PROFILE_INVALID',
            );
    }

    public function test_android_profile_uses_only_android_identity(): void
    {
        $provisioningId =
            $this->provision(
                'Android',
                'android',
            );

        $payload = $this->profilePayload(
            slug: 'pharmacy-android',
            androidId: 'com.storeapp.android',
            iosId: '',
        );
        $payload['ios_bundle_id'] = null;

        $this
            ->withHeaders($this->headers())
            ->putJson(
                '/api/v1/control-plane/provisioning/'.
                $provisioningId.
                '/build-profile',
                $payload,
            )
            ->assertCreated();

        $this
            ->withHeaders($this->headers())
            ->getJson(
                '/api/v1/control-plane/provisioning/'.
                $provisioningId.
                '/build-manifest'
            )
            ->assertOk()
            ->assertJsonPath(
                'data.manifest.app_instance.channel',
                'android',
            )
            ->assertJsonPath(
                'data.manifest.android.application_id',
                'com.storeapp.android',
            )
            ->assertJsonPath(
                'data.manifest.ios',
                null,
            );
    }

    public function test_ios_profile_uses_only_ios_identity(): void
    {
        $provisioningId =
            $this->provision(
                'Ios',
                'ios',
            );

        $payload = $this->profilePayload(
            slug: 'pharmacy-ios',
            androidId: '',
            iosId: 'com.storeapp.ios',
        );
        $payload['android_application_id'] = null;

        $this
            ->withHeaders($this->headers())
            ->putJson(
                '/api/v1/control-plane/provisioning/'.
                $provisioningId.
                '/build-profile',
                $payload,
            )
            ->assertCreated();

        $this
            ->withHeaders($this->headers())
            ->getJson(
                '/api/v1/control-plane/provisioning/'.
                $provisioningId.
                '/build-manifest'
            )
            ->assertOk()
            ->assertJsonPath(
                'data.manifest.app_instance.channel',
                'ios',
            )
            ->assertJsonPath(
                'data.manifest.android',
                null,
            )
            ->assertJsonPath(
                'data.manifest.ios.bundle_id',
                'com.storeapp.ios',
            );
    }

    public function test_web_instance_rejects_native_build_profile(): void
    {
        $provisioningId =
            $this->provision(
                'Web',
                'web',
            );

        $this
            ->withHeaders($this->headers())
            ->putJson(
                '/api/v1/control-plane/provisioning/'.
                $provisioningId.
                '/build-profile',
                $this->profilePayload(
                    slug: 'pharmacy-web',
                    androidId: 'com.storeapp.web',
                    iosId: 'com.storeapp.web',
                ),
            )
            ->assertStatus(422)
            ->assertJsonPath(
                'error.code',
                'BUILD_PROFILE_INVALID',
            );
    }

    public function test_web_profile_without_native_identifiers_is_supported(): void
    {
        $provisioningId =
            $this->provision(
                'WebPositive',
                'web',
            );

        $payload = $this->profilePayload(
            slug: 'pharmacy-web-positive',
            androidId: '',
            iosId: '',
            version: '2.0.0',
            build: 7,
        );

        $payload['android_application_id'] = null;
        $payload['ios_bundle_id'] = null;
        $payload['display_name'] =
            'Pharmacy Web';

        $this
            ->withHeaders($this->headers())
            ->putJson(
                '/api/v1/control-plane/provisioning/'.
                $provisioningId.
                '/build-profile',
                $payload,
            )
            ->assertCreated()
            ->assertJsonPath(
                'data.profile.android_application_id',
                null,
            )
            ->assertJsonPath(
                'data.profile.ios_bundle_id',
                null,
            );

        $this
            ->withHeaders($this->headers())
            ->getJson(
                '/api/v1/control-plane/provisioning/'.
                $provisioningId.
                '/build-manifest'
            )
            ->assertOk()
            ->assertJsonPath(
                'data.manifest.app_instance.channel',
                'web',
            )
            ->assertJsonPath(
                'data.manifest.android',
                null,
            )
            ->assertJsonPath(
                'data.manifest.ios',
                null,
            )
            ->assertJsonPath(
                'data.manifest.artifact_basename',
                'pharmacy-web-positive-2.0.0+7',
            );
    }

    public function test_manifest_requires_existing_profile(): void
    {
        $provisioningId =
            $this->provision();

        $this
            ->withHeaders($this->headers())
            ->getJson(
                '/api/v1/control-plane/provisioning/'.
                $provisioningId.
                '/build-manifest'
            )
            ->assertNotFound()
            ->assertJsonPath(
                'error.code',
                'BUILD_PROFILE_NOT_FOUND',
            );
    }

    public function test_database_rejects_profile_linked_to_wrong_provisioning_identity(): void
    {
        $alpha =
            $this->provision('Alpha');

        $beta =
            $this->provision('Beta');

        $alphaReceipt =
            StoreProvisioningReceipt::query()
                ->where(
                    'public_id',
                    $alpha,
                )
                ->firstOrFail();

        $betaReceipt =
            StoreProvisioningReceipt::query()
                ->where(
                    'public_id',
                    $beta,
                )
                ->firstOrFail();

        $this->expectException(
            QueryException::class
        );

        DB::table('app_build_profiles')->insert([
            'public_id' => '11111111-1111-4111-8111-111111111111',
            'store_provisioning_receipt_id' => $alphaReceipt->id,
            'tenant_id' => $betaReceipt->tenant_id,
            'app_instance_id' => $betaReceipt->app_instance_id,
            'slug' => 'cross-linked-profile',
            'display_name' => 'Cross Linked',
            'android_application_id' => 'com.storeapp.crosslinked',
            'ios_bundle_id' => 'com.storeapp.crosslinked',
            'version_name' => '1.0.0',
            'build_number' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
