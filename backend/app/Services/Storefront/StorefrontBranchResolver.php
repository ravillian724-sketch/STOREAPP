<?php

namespace App\Services\Storefront;

use App\Models\Branch;

final class StorefrontBranchResolver
{
    public function resolve(
        ?string $claimedBranchId,
    ): ?Branch {
        $claimed = trim(
            (string) $claimedBranchId
        );

        if ($claimed === '') {
            return Branch::query()
                ->where('is_active', true)
                ->orderBy('id')
                ->first();
        }

        if (! ctype_digit($claimed)) {
            return null;
        }

        return Branch::query()
            ->whereKey((int) $claimed)
            ->where('is_active', true)
            ->first();
    }
}
