<?php

namespace App\Support\Authorization;

final class SystemRoleCatalog
{
    public const OWNER = 'owner';

    public const MANAGER = 'manager';

    public const CASHIER = 'cashier';

    public const ACCOUNTANT = 'accountant';

    public const INVENTORY_MANAGER = 'inventory_manager';

    public const FULFILLMENT = 'fulfillment';

    public static function definitions(): array
    {
        return [
            self::OWNER => [
                'name' => 'Owner',
                'permissions' => array_keys(
                    PermissionCatalog::definitions()
                ),
            ],

            self::MANAGER => [
                'name' => 'Manager',
                'permissions' => [
                    PermissionCatalog::CATALOG_VIEW,
                    PermissionCatalog::CATALOG_MANAGE,
                    PermissionCatalog::INVENTORY_VIEW,
                    PermissionCatalog::INVENTORY_ADJUST,
                    PermissionCatalog::INVENTORY_TRANSFER,
                    PermissionCatalog::ORDERS_VIEW,
                    PermissionCatalog::ORDERS_MANAGE,
                    PermissionCatalog::PAYMENTS_VIEW,
                    PermissionCatalog::STAFF_VIEW,
                    PermissionCatalog::REPORTS_VIEW,
                    PermissionCatalog::SETTINGS_VIEW,
                ],
            ],

            self::CASHIER => [
                'name' => 'Cashier',
                'permissions' => [
                    PermissionCatalog::CATALOG_VIEW,
                    PermissionCatalog::INVENTORY_VIEW,
                    PermissionCatalog::ORDERS_VIEW,
                    PermissionCatalog::ORDERS_MANAGE,
                    PermissionCatalog::PAYMENTS_VIEW,
                ],
            ],

            self::ACCOUNTANT => [
                'name' => 'Accountant',
                'permissions' => [
                    PermissionCatalog::ORDERS_VIEW,
                    PermissionCatalog::PAYMENTS_VIEW,
                    PermissionCatalog::REPORTS_VIEW,
                ],
            ],

            self::INVENTORY_MANAGER => [
                'name' => 'Inventory Manager',
                'permissions' => [
                    PermissionCatalog::CATALOG_VIEW,
                    PermissionCatalog::CATALOG_MANAGE,
                    PermissionCatalog::INVENTORY_VIEW,
                    PermissionCatalog::INVENTORY_ADJUST,
                    PermissionCatalog::INVENTORY_TRANSFER,
                    PermissionCatalog::REPORTS_VIEW,
                ],
            ],

            self::FULFILLMENT => [
                'name' => 'Fulfillment',
                'permissions' => [
                    PermissionCatalog::CATALOG_VIEW,
                    PermissionCatalog::INVENTORY_VIEW,
                    PermissionCatalog::ORDERS_VIEW,
                    PermissionCatalog::ORDERS_MANAGE,
                ],
            ],
        ];
    }
}
