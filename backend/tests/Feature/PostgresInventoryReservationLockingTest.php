<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Tests\TestCase;

class PostgresInventoryReservationLockingTest extends TestCase
{
    use RefreshDatabase;

    public function test_competing_reservations_are_serialized_by_position_lock(): void
    {
        if (
            DB::connection()->getDriverName()
            !== 'pgsql'
        ) {
            $this->markTestSkipped(
                'PostgreSQL-specific reservation locking test.'
            );
        }

        $setup = $this->postgresConnection();

        $connectionA = null;
        $connectionB = null;
        $tenantId = null;

        try {
            /*
             * Create a committed fixture using an
             * independent PostgreSQL connection so
             * both competing sessions can see it.
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
                    'Race Tenant',
                    'Race Tenant',
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
                    'Race Product',
                    'Race Product',
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
                    'RACE-SKU',
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
                    'RACE-MAIN',
                    'RACE-MAIN',
                    'RACE-MAIN',
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

            $statement =
                $setup->prepare(
                    <<<'SQL'
                    INSERT INTO inventory_positions (
                        tenant_id,
                        sku_id,
                        location_id,
                        created_at,
                        updated_at
                    )
                    VALUES (
                        ?,
                        ?,
                        ?,
                        CURRENT_TIMESTAMP,
                        CURRENT_TIMESTAMP
                    )
                    SQL
                );

            $statement->execute([
                $tenantId,
                $skuId,
                $locationId,
            ]);

            $setup->commit();

            /*
             * Session A enters the critical section
             * and owns the SKU + location locks.
             */
            $connectionA =
                $this->postgresConnection();

            $connectionA->beginTransaction();

            $this->setTenant(
                $connectionA,
                $tenantId,
            );

            $this->lockPosition(
                $connectionA,
                $skuId,
                $locationId,
                false,
            );

            /*
             * A creates the reservation but does not
             * commit yet.
             */
            $statement =
                $connectionA->prepare(
                    <<<'SQL'
                    INSERT INTO inventory_reservations (
                        tenant_id,
                        sku_id,
                        location_id,
                        quantity,
                        status,
                        idempotency_key,
                        created_at,
                        updated_at
                    )
                    VALUES (
                        ?,
                        ?,
                        ?,
                        1,
                        'active',
                        'race-reservation-a',
                        CURRENT_TIMESTAMP,
                        CURRENT_TIMESTAMP
                    )
                    SQL
                );

            $statement->execute([
                $tenantId,
                $skuId,
                $locationId,
            ]);

            /*
             * Session B attempts to enter the SAME
             * critical section.
             *
             * NOWAIT converts "must wait" into the
             * deterministic PostgreSQL error 55P03.
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
                $this->lockPosition(
                    $connectionB,
                    $skuId,
                    $locationId,
                    true,
                );
            } catch (PDOException $exception) {
                $sqlState = (string) (
                    $exception->errorInfo[0]
                    ?? $exception->getCode()
                );
            }

            $this->assertSame(
                '55P03',
                $sqlState,
                'Second reservation session was not blocked by the position lock.',
            );

            if (
                $connectionB->inTransaction()
            ) {
                $connectionB->rollBack();
            }

            /*
             * Once A commits, B can enter.
             * It must now see A's committed active
             * reservation before calculating ATS.
             */
            $connectionA->commit();

            $connectionB->beginTransaction();

            $this->setTenant(
                $connectionB,
                $tenantId,
            );

            $this->lockPosition(
                $connectionB,
                $skuId,
                $locationId,
                false,
            );

            $statement =
                $connectionB->prepare(
                    <<<'SQL'
                    SELECT COALESCE(
                        SUM(quantity),
                        0
                    )
                    FROM inventory_reservations
                    WHERE sku_id = ?
                      AND location_id = ?
                      AND status = 'active'
                      AND (
                          expires_at IS NULL
                          OR expires_at >
                              CURRENT_TIMESTAMP
                      )
                    SQL
                );

            $statement->execute([
                $skuId,
                $locationId,
            ]);

            $reserved =
                (int) $statement->fetchColumn();

            $this->assertSame(
                1,
                $reserved,
                'Second session did not see the committed reservation after acquiring the lock.',
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

            /*
             * Remove the committed fixture.
             * No stock-ledger row was created, so
             * immutable-ledger protection is untouched.
             */
            if ($tenantId !== null) {
                $setup->beginTransaction();

                $this->setTenant(
                    $setup,
                    $tenantId,
                );

                foreach (
                    [
                        'inventory_reservations',
                        'inventory_positions',
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

    private function lockPosition(
        PDO $connection,
        int $skuId,
        int $locationId,
        bool $nowait,
    ): void {
        $suffix =
            $nowait
                ? ' NOWAIT'
                : '';

        $statement =
            $connection->prepare(
                'SELECT id
                 FROM inventory_positions
                 WHERE sku_id = ?
                   AND location_id = ?
                 FOR UPDATE'.$suffix
            );

        $statement->execute([
            $skuId,
            $locationId,
        ]);

        if (
            $statement->fetchColumn()
            === false
        ) {
            throw new \RuntimeException(
                'Inventory position lock row was not found.'
            );
        }
    }
}
