<?php

namespace App\Support\ControlPlane;

use App\Models\AppInstance;
use App\Models\AppInstanceCredential;
use App\Models\Branch;
use App\Models\StoreProvisioningReceipt;
use App\Models\Tenant;
use App\Models\User;

final class ProvisionedStore
{
    public function __construct(
        public readonly StoreProvisioningReceipt $receipt,
        public readonly Tenant $tenant,
        public readonly Branch $branch,
        public readonly AppInstance $appInstance,
        public readonly AppInstanceCredential $credential,
        public readonly User $owner,
        public readonly ?string $appInstanceToken,
        public readonly bool $replayed,
    ) {}

    public function credentialReissueRequired(): bool
    {
        return $this->replayed &&
            $this->appInstanceToken === null;
    }
}
