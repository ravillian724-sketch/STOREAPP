<?php

namespace App\Services\ControlPlane;

use App\Exceptions\ProvisioningIdempotencyConflictException;
use App\Models\AppInstance;
use App\Models\AppInstanceCredential;
use App\Models\Branch;
use App\Models\Role;
use App\Models\StoreProvisioningReceipt;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AppInstanceCredentialService;
use App\Services\Audit\AuditLogger;
use App\Services\TenantRbacProvisioner;
use App\Support\Authorization\SystemRoleCatalog;
use App\Support\ControlPlane\ProvisionedStore;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class StoreProvisioningService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantRbacProvisioner $rbac,
        private readonly AppInstanceCredentialService $credentials,
        private readonly AuditLogger $audit,
    ) {}

    public function provision(
        array $input,
        string $idempotencyKey,
        ?Request $request = null,
    ): ProvisionedStore {
        if ($this->tenantContext->id() !== null) {
            throw new LogicException(
                'Control plane provisioning requires no active tenant context.'
            );
        }

        $normalized =
            $this->normalize($input);

        $keyHash = hash(
            'sha256',
            trim($idempotencyKey),
        );

        $fingerprint =
            $this->fingerprint(
                $normalized
            );

        try {
            return DB::transaction(
                fn (): ProvisionedStore => $this->provisionLocked(
                    $normalized,
                    $keyHash,
                    $fingerprint,
                    $request,
                )
            );
        } catch (QueryException $error) {
            if (
                ! $this->isIdempotencyRace(
                    $error
                )
            ) {
                throw $error;
            }

            return DB::transaction(
                fn (): ProvisionedStore => $this->replayByHash(
                    $keyHash,
                    $fingerprint,
                )
            );
        } finally {
            $this->tenantContext->clear();
        }
    }

    private function provisionLocked(
        array $input,
        string $keyHash,
        string $fingerprint,
        ?Request $request,
    ): ProvisionedStore {
        $existing =
            StoreProvisioningReceipt::query()
                ->where(
                    'idempotency_key_hash',
                    $keyHash,
                )
                ->lockForUpdate()
                ->first();

        if ($existing !== null) {
            return $this->hydrateReplay(
                $existing,
                $fingerprint,
            );
        }

        $receipt =
            StoreProvisioningReceipt::query()
                ->create([
                    'public_id' => (string) Str::uuid(),
                    'idempotency_key_hash' => $keyHash,
                    'request_fingerprint' => $fingerprint,
                    'status' => 'provisioning',
                ]);

        $tenant = Tenant::query()->create([
            'name_ar' => $input['tenant']['name_ar'],
            'name_en' => $input['tenant']['name_en'],
            'country_code' => $input['tenant']['country_code'],
            'currency_code' => $input['tenant']['currency_code'],
            'vat_rate' => $input['tenant']['vat_rate'],
            'primary_color' => $input['tenant']['primary_color'],
            'secondary_color' => $input['tenant']['secondary_color'],
            'is_active' => true,
        ]);

        $this->tenantContext->set(
            (int) $tenant->id
        );

        $branch = Branch::query()->create([
            'code' => $input['branch']['code'],
            'name_ar' => $input['branch']['name_ar'],
            'name_en' => $input['branch']['name_en'],
            'is_active' => true,
        ]);

        $appInstance =
            AppInstance::query()->create([
                'tenant_id' => $tenant->id,
                'channel' => $input['app_instance']['channel'],
                'is_active' => true,
            ]);

        $issued =
            $this->credentials->issue(
                $appInstance
            );

        $this->rbac->provision(
            $tenant
        );

        $owner = User::query()->create([
            'name' => $input['owner']['name'],
            'email' => $input['owner']['email'],
            'password' => $input['owner']['password'],
            'is_active' => true,
        ]);

        $ownerRole = Role::query()
            ->where(
                'code',
                SystemRoleCatalog::OWNER,
            )
            ->where(
                'is_system',
                true,
            )
            ->first();

        if ($ownerRole === null) {
            throw new LogicException(
                'Provisioned tenant is missing the owner role.'
            );
        }

        $owner->assignRole(
            $ownerRole
        );

        $this->audit->record(
            action: 'tenant.provisioned',
            subjectType: 'tenant',
            subjectId: $tenant->id,
            actor: null,
            after: [
                'tenant_id' => $tenant->id,
                'branch_id' => $branch->id,
                'app_instance_id' => $appInstance->id,
                'owner_user_id' => $owner->id,
            ],
            metadata: [
                'provisioning_id' => $receipt->public_id,
                'channel' => $appInstance->channel,
            ],
            request: $request,
        );

        $receipt->forceFill([
            'status' => 'completed',
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'app_instance_id' => $appInstance->id,
            'app_instance_credential_id' => $issued->credential->id,
            'owner_user_id' => $owner->id,
        ])->save();

        return new ProvisionedStore(
            receipt: $receipt->fresh(),
            tenant: $tenant,
            branch: $branch,
            appInstance: $appInstance,
            credential: $issued->credential,
            owner: $owner,
            appInstanceToken: $issued->token,
            replayed: false,
        );
    }

    private function replayByHash(
        string $keyHash,
        string $fingerprint,
    ): ProvisionedStore {
        $receipt =
            StoreProvisioningReceipt::query()
                ->where(
                    'idempotency_key_hash',
                    $keyHash,
                )
                ->lockForUpdate()
                ->firstOrFail();

        return $this->hydrateReplay(
            $receipt,
            $fingerprint,
        );
    }

    private function hydrateReplay(
        StoreProvisioningReceipt $receipt,
        string $fingerprint,
    ): ProvisionedStore {
        if (
            ! hash_equals(
                $receipt->request_fingerprint,
                $fingerprint,
            )
        ) {
            throw new ProvisioningIdempotencyConflictException(
                'Provisioning idempotency key was reused with different data.'
            );
        }

        if (
            $receipt->status !== 'completed' ||
            $receipt->tenant_id === null ||
            $receipt->branch_id === null ||
            $receipt->app_instance_id === null ||
            $receipt->app_instance_credential_id === null ||
            $receipt->owner_user_id === null
        ) {
            throw new LogicException(
                'Provisioning receipt is incomplete.'
            );
        }

        $tenant = Tenant::query()
            ->findOrFail(
                $receipt->tenant_id
            );

        $this->tenantContext->set(
            (int) $tenant->id
        );

        $branch = Branch::query()
            ->findOrFail(
                $receipt->branch_id
            );

        $owner = User::query()
            ->findOrFail(
                $receipt->owner_user_id
            );

        $appInstance =
            AppInstance::query()
                ->where(
                    'tenant_id',
                    $tenant->id,
                )
                ->findOrFail(
                    $receipt->app_instance_id
                );

        $credential =
            AppInstanceCredential::query()
                ->where(
                    'app_instance_id',
                    $appInstance->id,
                )
                ->findOrFail(
                    $receipt
                        ->app_instance_credential_id
                );

        return new ProvisionedStore(
            receipt: $receipt,
            tenant: $tenant,
            branch: $branch,
            appInstance: $appInstance,
            credential: $credential,
            owner: $owner,
            appInstanceToken: null,
            replayed: true,
        );
    }

    private function normalize(
        array $input,
    ): array {
        return [
            'tenant' => [
                'name_ar' => trim(
                    $input['tenant']['name_ar']
                ),
                'name_en' => trim(
                    $input['tenant']['name_en']
                ),
                'country_code' => strtoupper(
                    trim(
                        $input['tenant']['country_code']
                    )
                ),
                'currency_code' => strtoupper(
                    trim(
                        $input['tenant']['currency_code']
                    )
                ),
                'vat_rate' => number_format(
                    (float) $input['tenant']['vat_rate'],
                    2,
                    '.',
                    '',
                ),
                'primary_color' => strtoupper(
                    trim(
                        $input['tenant']['primary_color']
                    )
                ),
                'secondary_color' => strtoupper(
                    trim(
                        $input['tenant']['secondary_color']
                    )
                ),
            ],
            'branch' => [
                'code' => strtoupper(
                    trim(
                        $input['branch']['code']
                    )
                ),
                'name_ar' => trim(
                    $input['branch']['name_ar']
                ),
                'name_en' => trim(
                    $input['branch']['name_en']
                ),
            ],
            'app_instance' => [
                'channel' => strtolower(
                    trim(
                        $input['app_instance']['channel']
                    )
                ),
            ],
            'owner' => [
                'name' => trim(
                    $input['owner']['name']
                ),
                'email' => strtolower(
                    trim(
                        $input['owner']['email']
                    )
                ),
                'password' => $input['owner']['password'],
            ],
        ];
    }

    private function fingerprint(
        array $input,
    ): string {
        $safe = $input;

        $safe['owner']['password'] =
            hash_hmac(
                'sha256',
                $input['owner']['password'],
                (string) config('app.key'),
            );

        return hash(
            'sha256',
            json_encode(
                $safe,
                JSON_THROW_ON_ERROR |
                JSON_UNESCAPED_SLASHES |
                JSON_UNESCAPED_UNICODE,
            ),
        );
    }

    private function isIdempotencyRace(
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
            'idempotency_key_hash',
        );
    }
}
