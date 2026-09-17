<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostgresOrderCartAppInstanceConstraintTest extends TestCase
{
    use RefreshDatabase;

    public function test_postgres_uses_composite_order_cart_app_instance_foreign_key(): void
    {
        if (
            DB::connection()->getDriverName()
            !== 'pgsql'
        ) {
            $this->markTestSkipped(
                'PostgreSQL-specific Order/Cart coherence proof.'
            );
        }

        $constraint =
            DB::selectOne(
                <<<'SQL'
                SELECT
                    pg_get_constraintdef(c.oid)
                        AS definition
                FROM pg_constraint c
                JOIN pg_class t
                  ON t.oid = c.conrelid
                WHERE t.relname = 'orders'
                  AND c.conname =
                    'orders_tenant_cart_app_instance_foreign'
                SQL
            );

        $this->assertNotNull(
            $constraint,
            'Composite Order/Cart/AppInstance FK is missing.'
        );

        $definition =
            preg_replace(
                '/\s+/',
                ' ',
                (string)
                $constraint->definition,
            );

        $this->assertStringContainsString(
            'FOREIGN KEY (tenant_id, cart_id, app_instance_id)',
            $definition,
        );

        $this->assertStringContainsString(
            'REFERENCES carts(tenant_id, id, app_instance_id)',
            $definition,
        );

        $oldConstraint =
            DB::selectOne(
                <<<'SQL'
                SELECT c.oid
                FROM pg_constraint c
                JOIN pg_class t
                  ON t.oid = c.conrelid
                WHERE t.relname = 'orders'
                  AND c.conname =
                    'orders_tenant_cart_foreign'
                SQL
            );

        $this->assertNull(
            $oldConstraint,
            'Weaker two-column Order -> Cart FK still exists.'
        );
    }
}
