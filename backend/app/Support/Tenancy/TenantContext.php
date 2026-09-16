<?php

namespace App\Support\Tenancy;

use RuntimeException;

final class TenantContext
{
    private ?int $tenantId = null;

    public function set(int $tenantId): void
    {
        if ($tenantId <= 0) {
            throw new RuntimeException('Invalid tenant identifier.');
        }

        $this->tenantId = $tenantId;
    }

    public function id(): ?int
    {
        return $this->tenantId;
    }

    public function requireId(): int
    {
        if ($this->tenantId === null) {
            throw new RuntimeException('Tenant context is not active.');
        }

        return $this->tenantId;
    }

    public function clear(): void
    {
        $this->tenantId = null;
    }
}
