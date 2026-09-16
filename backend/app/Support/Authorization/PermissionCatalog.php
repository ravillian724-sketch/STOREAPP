<?php

namespace App\Support\Authorization;

final class PermissionCatalog
{
    public const CATALOG_VIEW = 'catalog.view';

    public const CATALOG_MANAGE = 'catalog.manage';

    public const INVENTORY_VIEW = 'inventory.view';

    public const INVENTORY_ADJUST = 'inventory.adjust';

    public const INVENTORY_TRANSFER = 'inventory.transfer';

    public const ORDERS_VIEW = 'orders.view';

    public const ORDERS_MANAGE = 'orders.manage';

    public const ORDERS_REFUND = 'orders.refund';

    public const PAYMENTS_VIEW = 'payments.view';

    public const PAYMENTS_REFUND = 'payments.refund';

    public const STAFF_VIEW = 'staff.view';

    public const STAFF_MANAGE = 'staff.manage';

    public const ROLES_VIEW = 'roles.view';

    public const ROLES_MANAGE = 'roles.manage';

    public const REPORTS_VIEW = 'reports.view';

    public const SETTINGS_VIEW = 'settings.view';

    public const SETTINGS_MANAGE = 'settings.manage';

    public static function definitions(): array
    {
        return [
            self::CATALOG_VIEW => 'View catalog',
            self::CATALOG_MANAGE => 'Manage catalog',

            self::INVENTORY_VIEW => 'View inventory',
            self::INVENTORY_ADJUST => 'Adjust inventory',
            self::INVENTORY_TRANSFER => 'Transfer inventory',

            self::ORDERS_VIEW => 'View orders',
            self::ORDERS_MANAGE => 'Manage orders',
            self::ORDERS_REFUND => 'Refund orders',

            self::PAYMENTS_VIEW => 'View payments',
            self::PAYMENTS_REFUND => 'Refund payments',

            self::STAFF_VIEW => 'View staff',
            self::STAFF_MANAGE => 'Manage staff',

            self::ROLES_VIEW => 'View roles',
            self::ROLES_MANAGE => 'Manage roles',

            self::REPORTS_VIEW => 'View reports',

            self::SETTINGS_VIEW => 'View settings',
            self::SETTINGS_MANAGE => 'Manage settings',
        ];
    }
}
