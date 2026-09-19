<?php

namespace App\Services\ControlPlane;

use App\Exceptions\BuildProfileConflictException;
use App\Exceptions\BuildProfileValidationException;
use App\Models\AppBuildProfile;
use App\Models\AppInstance;
use App\Models\StoreProvisioningReceipt;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Support\ControlPlane\ConfiguredBuildProfile;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

final class BuildProfileService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $audit,
    ) {}

    public function configure(
        string $provisioningPublicId,
        array $input,
        ?Request $request = null,
    ): ConfiguredBuildProfile {
        $this->requireControlPlaneContext();

        try {
            return DB::transaction(
                function () use (
                    $provisioningPublicId,
                    $input,
                    $request,
                ): ConfiguredBuildProfile {
                    $receipt = $this->receipt(
                        $provisioningPublicId,
                    );

                    $this->tenantContext->set(
                        (int) $receipt->tenant_id
                    );

                    $appInstance = AppInstance::query()
                        ->where(
                            'tenant_id',
                            $receipt->tenant_id,
                        )
                        ->findOrFail(
                            $receipt->app_instance_id
                        );

                    $normalized = $this->normalize(
                        $input
                    );

                    $this->validateChannelIdentity(
                        $appInstance,
                        $normalized,
                    );

                    $profile = AppBuildProfile::query()
                        ->where(
                            'store_provisioning_receipt_id',
                            $receipt->id,
                        )
                        ->lockForUpdate()
                        ->first();

                    $created = $profile === null;

                    if ($profile === null) {
                        $profile = new AppBuildProfile([
                            'store_provisioning_receipt_id' => $receipt->id,
                            'tenant_id' => $receipt->tenant_id,
                            'app_instance_id' => $appInstance->id,
                            'slug' => $normalized['slug'],
                            'android_application_id' => $normalized['android_application_id'],
                            'ios_bundle_id' => $normalized['ios_bundle_id'],
                        ]);
                    } else {
                        $this->assertIdentityUnchanged(
                            $profile,
                            $normalized,
                        );

                        if (
                            $normalized['build_number'] <
                            $profile->build_number
                        ) {
                            throw new BuildProfileValidationException(
                                'Build number cannot decrease.'
                            );
                        }

                        if (
                            $normalized['build_number'] ===
                                $profile->build_number &&
                            $this->mutableMetadataChanged(
                                $profile,
                                $normalized,
                            )
                        ) {
                            throw new BuildProfileValidationException(
                                'Build number must increase when build metadata changes.'
                            );
                        }
                    }

                    $before = $profile->exists
                        ? $this->auditValues($profile)
                        : null;

                    $profile->fill([
                        'display_name' => $normalized['display_name'],
                        'version_name' => $normalized['version_name'],
                        'build_number' => $normalized['build_number'],
                        'is_active' => true,
                    ]);

                    try {
                        $profile->save();
                    } catch (QueryException $error) {
                        if (
                            $this->isProfileIdentityConflict(
                                $error
                            )
                        ) {
                            throw new BuildProfileConflictException(
                                'Build identity is already in use.',
                                previous: $error,
                            );
                        }

                        throw $error;
                    }

                    $after = $this->auditValues(
                        $profile
                    );

                    if (
                        $created ||
                        $before !== $after
                    ) {
                        $this->audit->record(
                            action: $created
                                ? 'build_profile.created'
                                : 'build_profile.updated',
                            subjectType: 'app_build_profile',
                            subjectId: $profile->id,
                            actor: null,
                            before: $before,
                            after: $after,
                            metadata: [
                                'provisioning_id' => $receipt->public_id,
                                'channel' => $appInstance->channel,
                            ],
                            request: $request,
                        );
                    }

                    return new ConfiguredBuildProfile(
                        profile: $profile->fresh(),
                        created: $created,
                    );
                }
            );
        } finally {
            $this->tenantContext->clear();
        }
    }

    public function manifest(
        string $provisioningPublicId,
    ): array {
        $this->requireControlPlaneContext();

        try {
            return DB::transaction(
                function () use (
                    $provisioningPublicId,
                ): array {
                    $receipt = $this->receipt(
                        $provisioningPublicId,
                    );

                    $this->tenantContext->set(
                        (int) $receipt->tenant_id
                    );

                    $tenant = Tenant::query()
                        ->findOrFail(
                            $receipt->tenant_id
                        );

                    $appInstance = AppInstance::query()
                        ->where(
                            'tenant_id',
                            $tenant->id,
                        )
                        ->findOrFail(
                            $receipt->app_instance_id
                        );

                    $profile = AppBuildProfile::query()
                        ->where(
                            'store_provisioning_receipt_id',
                            $receipt->id,
                        )
                        ->where(
                            'tenant_id',
                            $tenant->id,
                        )
                        ->where(
                            'app_instance_id',
                            $appInstance->id,
                        )
                        ->where(
                            'is_active',
                            true,
                        )
                        ->firstOrFail();

                    return $this->buildManifest(
                        $receipt,
                        $tenant,
                        $appInstance,
                        $profile,
                    );
                }
            );
        } finally {
            $this->tenantContext->clear();
        }
    }

    private function receipt(
        string $publicId,
    ): StoreProvisioningReceipt {
        $receipt = StoreProvisioningReceipt::query()
            ->where(
                'public_id',
                trim($publicId),
            )
            ->lockForUpdate()
            ->firstOrFail();

        if (
            $receipt->status !== 'completed' ||
            $receipt->tenant_id === null ||
            $receipt->app_instance_id === null
        ) {
            throw new BuildProfileValidationException(
                'Provisioning must be completed before configuring a build profile.'
            );
        }

        return $receipt;
    }

    private function normalize(
        array $input,
    ): array {
        return [
            'slug' => strtolower(
                trim($input['slug'])
            ),
            'display_name' => trim(
                $input['display_name']
            ),
            'android_application_id' => $this->nullableLower(
                $input['android_application_id']
                    ?? null
            ),
            'ios_bundle_id' => $this->nullableLower(
                $input['ios_bundle_id']
                    ?? null
            ),
            'version_name' => trim(
                $input['version_name']
            ),
            'build_number' => (int) $input['build_number'],
        ];
    }

    private function nullableLower(
        mixed $value,
    ): ?string {
        if ($value === null) {
            return null;
        }

        $normalized = strtolower(
            trim((string) $value)
        );

        return $normalized === ''
            ? null
            : $normalized;
    }

    private function validateChannelIdentity(
        AppInstance $appInstance,
        array $input,
    ): void {
        $channel = strtolower(
            trim($appInstance->channel)
        );

        if ($channel === 'mobile') {
            if (
                $input['android_application_id'] === null ||
                $input['ios_bundle_id'] === null
            ) {
                throw new BuildProfileValidationException(
                    'Mobile build profiles require Android and iOS identifiers.'
                );
            }

            return;
        }

        if ($channel === 'android') {
            if (
                $input['android_application_id'] === null ||
                $input['ios_bundle_id'] !== null
            ) {
                throw new BuildProfileValidationException(
                    'Android build profiles require only an Android application id.'
                );
            }

            return;
        }

        if ($channel === 'ios') {
            if (
                $input['ios_bundle_id'] === null ||
                $input['android_application_id'] !== null
            ) {
                throw new BuildProfileValidationException(
                    'iOS build profiles require only an iOS bundle id.'
                );
            }

            return;
        }

        if ($channel === 'web') {
            if (
                $input['android_application_id'] !== null ||
                $input['ios_bundle_id'] !== null
            ) {
                throw new BuildProfileValidationException(
                    'Web build profiles cannot use native application identifiers.'
                );
            }

            return;
        }

        throw new BuildProfileValidationException(
            'Build profiles are not supported for this app instance channel.'
        );
    }

    private function assertIdentityUnchanged(
        AppBuildProfile $profile,
        array $input,
    ): void {
        foreach (
            [
                'slug',
                'android_application_id',
                'ios_bundle_id',
            ] as $field
        ) {
            if (
                $profile->{$field} !==
                $input[$field]
            ) {
                throw new BuildProfileConflictException(
                    'Build profile identity cannot be changed.'
                );
            }
        }
    }

    private function mutableMetadataChanged(
        AppBuildProfile $profile,
        array $input,
    ): bool {
        return
            $profile->display_name !==
                $input['display_name'] ||
            $profile->version_name !==
                $input['version_name'] ||
            ! $profile->is_active;
    }

    private function auditValues(
        AppBuildProfile $profile,
    ): array {
        return [
            'profile_id' => $profile->public_id,
            'slug' => $profile->slug,
            'display_name' => $profile->display_name,
            'android_application_id' => $profile->android_application_id,
            'ios_bundle_id' => $profile->ios_bundle_id,
            'version_name' => $profile->version_name,
            'build_number' => $profile->build_number,
            'is_active' => $profile->is_active,
        ];
    }

    private function buildManifest(
        StoreProvisioningReceipt $receipt,
        Tenant $tenant,
        AppInstance $appInstance,
        AppBuildProfile $profile,
    ): array {
        return [
            'schema_version' => 1,
            'provisioning_id' => $receipt->public_id,
            'profile' => [
                'id' => $profile->public_id,
                'slug' => $profile->slug,
                'display_name' => $profile->display_name,
                'version_name' => $profile->version_name,
                'build_number' => $profile->build_number,
            ],
            'tenant' => [
                'name_ar' => $tenant->name_ar,
                'name_en' => $tenant->name_en,
                'country_code' => $tenant->country_code,
                'currency_code' => $tenant->currency_code,
                'primary_color' => $tenant->primary_color,
                'secondary_color' => $tenant->secondary_color,
            ],
            'app_instance' => [
                'channel' => $appInstance->channel,
            ],
            'android' => $profile->android_application_id === null
                    ? null
                    : [
                        'application_id' => $profile->android_application_id,
                    ],
            'ios' => $profile->ios_bundle_id === null
                    ? null
                    : [
                        'bundle_id' => $profile->ios_bundle_id,
                    ],
            'runtime' => [
                'app_env' => 'production',
                'app_instance_key_required' => true,
            ],
            'artifact_basename' => $profile->slug.
                '-'.
                $profile->version_name.
                '+'.
                $profile->build_number,
        ];
    }

    private function isProfileIdentityConflict(
        QueryException $error,
    ): bool {
        $sqlState = (string) (
            $error->errorInfo[0]
            ?? $error->getCode()
        );

        if (
            ! in_array(
                $sqlState,
                [
                    '23000',
                    '23505',
                ],
                true,
            )
        ) {
            return false;
        }

        return str_contains(
            strtolower(
                $error->getMessage()
            ),
            'app_build_profiles',
        );
    }

    private function requireControlPlaneContext(): void
    {
        if (
            $this->tenantContext->id()
            !== null
        ) {
            throw new LogicException(
                'Build profile management requires no active tenant context.'
            );
        }
    }
}
