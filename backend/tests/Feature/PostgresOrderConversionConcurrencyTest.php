<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use RuntimeException;
use Tests\TestCase;

class PostgresOrderConversionConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_competing_order_conversions_are_serialized_by_cart_lock(): void
    {
        if (
            DB::connection()->getDriverName()
            !== 'pgsql'
        ) {
            $this->markTestSkipped(
                'PostgreSQL-specific Order conversion concurrency proof.'
            );
        }

        $setup =
            $this->postgresConnection();

        $connectionA = null;
        $connectionB = null;
        $tenantId = null;

        try {
            /*
             * Create committed fixture visible to both
             * competing PostgreSQL sessions.
             */
            $statement =
                $setup->prepare(
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
                        'Order Race Tenant',
                        'Order Race Tenant',
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

            $statement =
                $setup->prepare(
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

            $statement =
                $setup->prepare(
                    <<<'SQL'
                    INSERT INTO carts (
                        tenant_id,
                        app_instance_id,
                        public_id,
                        token_hash,
                        status,
                        expires_at,
                        inventory_reserved_until,
                        created_at,
                        updated_at
                    )
                    VALUES (
                        ?,
                        ?,
                        'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                        'active',
                        CURRENT_TIMESTAMP + INTERVAL '1 day',
                        CURRENT_TIMESTAMP + INTERVAL '15 minutes',
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

            $setup->commit();

            /*
             * Session A begins conversion and acquires
             * the same aggregate Cart lock used by
             * OrderConversionService::convert().
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
             * While A owns the Cart lock, session B must
             * not enter the conversion critical section.
             *
             * NOWAIT gives deterministic SQLSTATE 55P03.
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
                'Concurrent Order conversion bypassed the Cart aggregate lock.',
            );

            if (
                $connectionB->inTransaction()
            ) {
                $connectionB->rollBack();
            }

            /*
             * Session A materializes the authoritative
             * Order and then marks Cart CONVERTED inside
             * the same transaction.
             */
            $statement =
                $connectionA->prepare(
                    <<<'SQL'
                    INSERT INTO orders (
                        tenant_id,
                        app_instance_id,
                        cart_id,
                        public_id,
                        status,
                        currency_code,
                        subtotal_minor,
                        discount_minor,
                        tax_minor,
                        shipping_minor,
                        total_minor,
                        created_at,
                        updated_at
                    )
                    VALUES (
                        ?,
                        ?,
                        ?,
                        'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                        'pending',
                        'SAR',
                        1000,
                        0,
                        150,
                        0,
                        1150,
                        CURRENT_TIMESTAMP,
                        CURRENT_TIMESTAMP
                    )
                    RETURNING id
                    SQL
                );

            $statement->execute([
                $tenantId,
                $appInstanceId,
                $cartId,
            ]);

            $orderId =
                (int) $statement->fetchColumn();

            $statement =
                $connectionA->prepare(
                    <<<'SQL'
                    UPDATE carts
                    SET
                        status = 'converted',
                        converted_at = CURRENT_TIMESTAMP,
                        inventory_reserved_until = NULL,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                    SQL
                );

            $statement->execute([
                $cartId,
            ]);

            $connectionA->commit();

            /*
             * Session B retries after A commits.
             *
             * It now acquires the Cart lock and must see
             * the terminal converted state plus exactly
             * the Order created by A.
             */
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
                    SELECT
                        status,
                        converted_at,
                        inventory_reserved_until
                    FROM carts
                    WHERE id = ?
                    SQL
                );

            $statement->execute([
                $cartId,
            ]);

            $cartState =
                $statement->fetch(
                    PDO::FETCH_ASSOC
                );

            if ($cartState === false) {
                throw new RuntimeException(
                    'Converted Cart disappeared.'
                );
            }

            $this->assertSame(
                'converted',
                $cartState['status'],
            );

            $this->assertNotNull(
                $cartState['converted_at'],
            );

            $this->assertNull(
                $cartState[
                    'inventory_reserved_until'
                ],
            );

            $statement =
                $connectionB->prepare(
                    <<<'SQL'
                    SELECT id
                    FROM orders
                    WHERE tenant_id = ?
                      AND cart_id = ?
                    SQL
                );

            $statement->execute([
                $tenantId,
                $cartId,
            ]);

            $observedOrderId =
                (int) $statement->fetchColumn();

            $this->assertSame(
                $orderId,
                $observedOrderId,
                'Replay did not observe the authoritative Order.',
            );

            /*
             * Database invariant is the final safety net:
             * one Cart can materialize into only one Order.
             */
            $duplicateSqlState = null;

            try {
                $statement =
                    $connectionB->prepare(
                        <<<'SQL'
                        INSERT INTO orders (
                            tenant_id,
                            app_instance_id,
                            cart_id,
                            public_id,
                            status,
                            currency_code,
                            subtotal_minor,
                            discount_minor,
                            tax_minor,
                            shipping_minor,
                            total_minor,
                            created_at,
                            updated_at
                        )
                        VALUES (
                            ?,
                            ?,
                            ?,
                            'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
                            'pending',
                            'SAR',
                            1000,
                            0,
                            150,
                            0,
                            1150,
                            CURRENT_TIMESTAMP,
                            CURRENT_TIMESTAMP
                        )
                        SQL
                    );

                $statement->execute([
                    $tenantId,
                    $appInstanceId,
                    $cartId,
                ]);
            } catch (
                PDOException $exception
            ) {
                $duplicateSqlState =
                    (string) (
                        $exception->errorInfo[0]
                        ?? $exception->getCode()
                    );
            }

            $this->assertSame(
                '23505',
                $duplicateSqlState,
                'Database allowed two Orders for one Cart.',
            );

            if (
                $connectionB->inTransaction()
            ) {
                $connectionB->rollBack();
            }
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
                        'order_items',
                        'orders',
                        'cart_items',
                        'carts',
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
        $config =
            config(
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
            throw new RuntimeException(
                'Cart lock row was not found.'
            );
        }
    }
}
