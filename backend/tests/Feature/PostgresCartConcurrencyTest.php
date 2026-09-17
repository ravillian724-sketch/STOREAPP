<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Tests\TestCase;

class PostgresCartConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_competing_cart_mutations_are_serialized_by_cart_lock(): void
    {
        if (
            DB::connection()->getDriverName()
            !== 'pgsql'
        ) {
            $this->markTestSkipped(
                'PostgreSQL-specific cart concurrency test.'
            );
        }

        $setup = $this->postgresConnection();

        $connectionA = null;
        $connectionB = null;
        $tenantId = null;

        try {
            /*
             * Fixture is committed through an independent
             * connection so both competing sessions see
             * exactly the same database state.
             */
            $statement = $setup->prepare(
                <<<'SQL'
                INSERT INTO tenants (
                    name_ar,
                    name_en,
                    country_code,
                    currency_code,
                    vat_rate,
                    primary_color,
                    secondary_color,
                    is_active,
                    created_at,
                    updated_at
                )
                VALUES (
                    'Cart Race Tenant',
                    'Cart Race Tenant',
                    'SA',
                    'SAR',
                    15,
                    '#000000',
                    '#FFFFFF',
                    true,
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP
                )
                RETURNING id
                SQL
            );

            $statement->execute();

            $tenantId =
                (int) $statement->fetchColumn();

            $setup->beginTransaction();

            $this->setTenant(
                $setup,
                $tenantId,
            );

            $statement = $setup->prepare(
                <<<'SQL'
                INSERT INTO app_instances (
                    tenant_id,
                    channel,
                    is_active,
                    created_at,
                    updated_at
                )
                VALUES (
                    ?,
                    'mobile',
                    true,
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP
                )
                RETURNING id
                SQL
            );

            $statement->execute([
                $tenantId,
            ]);

            $appInstanceId =
                (int) $statement->fetchColumn();

            $statement = $setup->prepare(
                <<<'SQL'
                INSERT INTO products (
                    tenant_id,
                    name_ar,
                    name_en,
                    is_active,
                    created_at,
                    updated_at
                )
                VALUES (
                    ?,
                    'Cart Race Product',
                    'Cart Race Product',
                    true,
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP
                )
                RETURNING id
                SQL
            );

            $statement->execute([
                $tenantId,
            ]);

            $productId =
                (int) $statement->fetchColumn();

            $statement = $setup->prepare(
                <<<'SQL'
                INSERT INTO skus (
                    tenant_id,
                    product_id,
                    code,
                    track_inventory,
                    is_active,
                    created_at,
                    updated_at
                )
                VALUES (
                    ?,
                    ?,
                    'CART-RACE-SKU',
                    true,
                    true,
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP
                )
                RETURNING id
                SQL
            );

            $statement->execute([
                $tenantId,
                $productId,
            ]);

            $skuId =
                (int) $statement->fetchColumn();

            $statement = $setup->prepare(
                <<<'SQL'
                INSERT INTO inventory_locations (
                    tenant_id,
                    branch_id,
                    code,
                    name_ar,
                    name_en,
                    type,
                    is_active,
                    created_at,
                    updated_at
                )
                VALUES (
                    ?,
                    NULL,
                    'CART-RACE-MAIN',
                    'CART-RACE-MAIN',
                    'CART-RACE-MAIN',
                    'stock',
                    true,
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP
                )
                RETURNING id
                SQL
            );

            $statement->execute([
                $tenantId,
            ]);

            $locationId =
                (int) $statement->fetchColumn();

            $statement = $setup->prepare(
                <<<'SQL'
                INSERT INTO carts (
                    tenant_id,
                    app_instance_id,
                    public_id,
                    token_hash,
                    status,
                    expires_at,
                    created_at,
                    updated_at
                )
                VALUES (
                    ?,
                    ?,
                    '11111111-1111-4111-8111-111111111111',
                    'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                    'active',
                    CURRENT_TIMESTAMP + INTERVAL '1 day',
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP
                )
                RETURNING id
                SQL
            );

            $statement->execute([
                $tenantId,
                $appInstanceId,
            ]);

            $cartId =
                (int) $statement->fetchColumn();

            $statement = $setup->prepare(
                <<<'SQL'
                INSERT INTO cart_items (
                    tenant_id,
                    cart_id,
                    sku_id,
                    location_id,
                    public_id,
                    quantity,
                    created_at,
                    updated_at
                )
                VALUES (
                    ?,
                    ?,
                    ?,
                    ?,
                    '22222222-2222-4222-8222-222222222222',
                    2,
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP
                )
                RETURNING id
                SQL
            );

            $statement->execute([
                $tenantId,
                $cartId,
                $skuId,
                $locationId,
            ]);

            $itemId =
                (int) $statement->fetchColumn();

            $setup->commit();

            /*
             * Session A enters the cart aggregate
             * critical section.
             */
            $connectionA =
                $this->postgresConnection();

            $connectionA->beginTransaction();

            $this->setTenant(
                $connectionA,
                $tenantId,
            );

            $this->lockCart(
                $connectionA,
                $cartId,
                false,
            );

            /*
             * Simulate the mutation performed after
             * CartService acquires lockMutableCart().
             * Keep it uncommitted.
             */
            $statement =
                $connectionA->prepare(
                    <<<'SQL'
                    UPDATE cart_items
                    SET
                        quantity = 5,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                      AND cart_id = ?
                    SQL
                );

            $statement->execute([
                $itemId,
                $cartId,
            ]);

            /*
             * Session B tries to mutate the same cart.
             *
             * NOWAIT converts PostgreSQL waiting into
             * deterministic SQLSTATE 55P03.
             */
            $connectionB =
                $this->postgresConnection();

            $connectionB->beginTransaction();

            $this->setTenant(
                $connectionB,
                $tenantId,
            );

            $sqlState = null;

            try {
                $this->lockCart(
                    $connectionB,
                    $cartId,
                    true,
                );
            } catch (
                PDOException $exception
            ) {
                $sqlState =
                    (string) (
                        $exception->errorInfo[0]
                        ?? $exception->getCode()
                    );
            }

            $this->assertSame(
                '55P03',
                $sqlState,
                'Concurrent cart mutation bypassed the aggregate cart lock.',
            );

            if (
                $connectionB->inTransaction()
            ) {
                $connectionB->rollBack();
            }

            /*
             * After A commits, B can acquire the cart
             * lock and must observe A's committed state.
             */
            $connectionA->commit();

            $connectionB->beginTransaction();

            $this->setTenant(
                $connectionB,
                $tenantId,
            );

            $this->lockCart(
                $connectionB,
                $cartId,
                false,
            );

            $statement =
                $connectionB->prepare(
                    <<<'SQL'
                    SELECT quantity
                    FROM cart_items
                    WHERE id = ?
                      AND cart_id = ?
                    SQL
                );

            $statement->execute([
                $itemId,
                $cartId,
            ]);

            $this->assertSame(
                5,
                (int) $statement->fetchColumn(),
                'Second cart mutation did not observe the committed first mutation.',
            );

            $connectionB->rollBack();
        } finally {
            if (
                $connectionA instanceof PDO &&
                $connectionA->inTransaction()
            ) {
                $connectionA->rollBack();
            }

            if (
                $connectionB instanceof PDO &&
                $connectionB->inTransaction()
            ) {
                $connectionB->rollBack();
            }

            if ($setup->inTransaction()) {
                $setup->rollBack();
            }

            if ($tenantId !== null) {
                $setup->beginTransaction();

                $this->setTenant(
                    $setup,
                    $tenantId,
                );

                foreach (
                    [
                        'cart_items',
                        'carts',
                        'inventory_locations',
                        'skus',
                        'products',
                    ] as $table
                ) {
                    $statement =
                        $setup->prepare(
                            "DELETE FROM {$table}
                             WHERE tenant_id = ?"
                        );

                    $statement->execute([
                        $tenantId,
                    ]);
                }

                $statement =
                    $setup->prepare(
                        'DELETE FROM app_instances
                         WHERE tenant_id = ?'
                    );

                $statement->execute([
                    $tenantId,
                ]);

                $setup->commit();

                $statement =
                    $setup->prepare(
                        'DELETE FROM tenants
                         WHERE id = ?'
                    );

                $statement->execute([
                    $tenantId,
                ]);
            }
        }
    }

    private function postgresConnection(): PDO
    {
        $config = config(
            'database.connections.pgsql'
        );

        return new PDO(
            sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                $config['host'],
                $config['port'],
                $config['database'],
            ),
            $config['username'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,

                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }

    private function setTenant(
        PDO $connection,
        int $tenantId,
    ): void {
        $statement =
            $connection->prepare(
                <<<'SQL'
                SELECT set_config(
                    'storeapp.tenant_id',
                    ?,
                    true
                )
                SQL
            );

        $statement->execute([
            (string) $tenantId,
        ]);
    }

    private function lockCart(
        PDO $connection,
        int $cartId,
        bool $nowait,
    ): void {
        $suffix =
            $nowait
                ? ' NOWAIT'
                : '';

        $statement =
            $connection->prepare(
                'SELECT id
                 FROM carts
                 WHERE id = ?
                 FOR UPDATE'.$suffix
            );

        $statement->execute([
            $cartId,
        ]);

        if (
            $statement->fetchColumn()
            === false
        ) {
            throw new \RuntimeException(
                'Cart lock row was not found.'
            );
        }
    }
}
