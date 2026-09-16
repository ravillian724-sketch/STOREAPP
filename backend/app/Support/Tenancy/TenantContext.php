<?php

namespace App\Support\Tenancy;

use RuntimeException;

final class TenantContext
{
    private ?int $tenantId = null;

    public function __construct(
        private readonly TenantDatabaseContext $databaseContext,
    ) {}

    public function set(int $tenantId): void
    {
        if ($tenantId <= 0) {
            throw new RuntimeException(
                'Invalid tenant identifier.'
            );
        }

        $this->databaseContext->set(
            $tenantId
        );

        $this->tenantId = $tenantId;
    }

    public function id(): ?int
    {
        return $this->tenantId;
    }

    public function requireId(): int
    {
        if ($this->tenantId === null) {
            throw new RuntimeException(
                'Tenant context is not active.'
            );
        }

        return $this->tenantId;
    }

    public function clear(): void
    {
        // Clear the in-memory identity first so a failed
        // database cleanup cannot leave the application
        // believing a tenant is still active.
        $this->tenantId = null;

        $this->databaseContext->clear();
    }
}
