<?php

namespace App\Support\Audit;

final class AdminAuditAction
{
    public const STAFF_CREATED =
        'staff.created';

    public const STAFF_UPDATED =
        'staff.updated';

    public const ROLE_CREATED =
        'role.created';

    public const ROLE_UPDATED =
        'role.updated';

    public const PRODUCT_CREATED =
        'catalog.product.created';

    public const PRODUCT_UPDATED =
        'catalog.product.updated';

    public const SKU_CREATED =
        'catalog.sku.created';

    public const SKU_UPDATED =
        'catalog.sku.updated';

    private function __construct() {}
}
