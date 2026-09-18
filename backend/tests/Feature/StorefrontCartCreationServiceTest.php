<?php

namespace Tests\Feature;

use App\Models\AppInstance;
use App\Models\CartCreationReceipt;
use App\Models\Tenant;
use App\Services\Storefront\StorefrontCartCreationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class StorefrontCartCreationServiceTest extends TestCase
{
    use RefreshDatabase;

    private StorefrontCartCreationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service =
            app(
                StorefrontCartCreationService::class
            );
    }

    private function tenant(
        string $name,
    ): Tenant {
        return Tenant::query()->create([
            'name_ar' => $name,
            'name_en' => $name,
            'country_code' => 'SA',
            'currency_code' => 'SAR',
            'vat_rate' => 15,
            'primary_color' => '#006C67',
            'secondary_color' => '#0F172A',
            'is_active' => true,
        ]);
    }

    private function appInstance(
        Tenant $tenant,
        bool $active = true,
    ): AppInstance {
        return AppInstance::query()->create([
            'tenant_id' => $tenant->id,
            'channel' => 'mobile',
            'is_active' => $active,
        ]);
    }

    private function inTenant(
        Tenant $tenant,
        callable $callback,
    ): mixed {
        $context =
            app(TenantContext::class);

        $previous =
            $context->id();

        $context->set(
            $tenant->id
        );

        try {
            return $callback();
        } finally {
            if ($previous === null) {
                $context->clear();
            } else {
                $context->set(
                    $previous
                );
            }
        }
    }

    public function test_same_creation_key_replays_same_cart_and_token(): void
    {
        $tenant =
            $this->tenant(
                'Tenant A'
            );

        $instance =
            $this->appInstance(
                $tenant
            );

        [
            $first,
            $second,
            $receiptCount,
        ] = $this->inTenant(
            $tenant,
            function () use ($instance): array {
                $first =
                    $this->service->create(
                        $instance,
                        'create-cart-001',
                    );

                $second =
                    $this->service->create(
                        $instance,
                        'create-cart-001',
                    );

                return [
                    $first,
                    $second,
                    CartCreationReceipt::query()
                        ->count(),
                ];
            },
        );

        $this->assertSame(
            $first->cart->id,
            $second->cart->id,
        );

        $this->assertSame(
            $first->token,
            $second->token,
        );

        $this->assertSame(
            1,
            $receiptCount,
        );
    }

    public function test_creation_receipt_never_persists_plaintext_token(): void
    {
        $tenant =
            $this->tenant(
                'Tenant A'
            );

        $instance =
            $this->appInstance(
                $tenant
            );

        [
            $created,
            $rawReceipt,
        ] = $this->inTenant(
            $tenant,
            function () use ($instance): array {
                $created =
                    $this->service->create(
                        $instance,
                        'create-cart-secret-proof',
                    );

                $rawReceipt =
                    DB::table(
                        'cart_creation_receipts'
                    )->first();

                return [
                    $created,
                    $rawReceipt,
                ];
            },
        );

        $this->assertNotNull(
            $rawReceipt
        );

        $this->assertSame(
            hash(
                'sha256',
                $created->token,
            ),
            $created->cart->token_hash,
        );

        $serialized =
            json_encode(
                (array) $rawReceipt,
                JSON_THROW_ON_ERROR,
            );

        $this->assertStringNotContainsString(
            $created->token,
            $serialized,
        );

        $this->assertArrayNotHasKey(
            'token_hash',
            $created->cart->toArray(),
        );
    }

    public function test_same_key_is_independent_between_app_instances(): void
    {
        $tenant =
            $this->tenant(
                'Tenant A'
            );

        $firstInstance =
            $this->appInstance(
                $tenant
            );

        $secondInstance =
            $this->appInstance(
                $tenant
            );

        [
            $first,
            $second,
        ] = $this->inTenant(
            $tenant,
            fn (): array => [
                $this->service->create(
                    $firstInstance,
                    'shared-create-key',
                ),
                $this->service->create(
                    $secondInstance,
                    'shared-create-key',
                ),
            ],
        );

        $this->assertNotSame(
            $first->cart->id,
            $second->cart->id,
        );

        $this->assertNotSame(
            $first->token,
            $second->token,
        );
    }

    public function test_invalid_creation_keys_are_rejected_before_persistence(): void
    {
        $tenant =
            $this->tenant(
                'Tenant A'
            );

        $instance =
            $this->appInstance(
                $tenant
            );

        foreach ([
            '',
            '   ',
            str_repeat(
                'x',
                121,
            ),
        ] as $invalidKey) {
            try {
                $this->inTenant(
                    $tenant,
                    fn () => $this->service->create(
                        $instance,
                        $invalidKey,
                    ),
                );

                $this->fail(
                    'Invalid creation key was accepted.'
                );
            } catch (
                InvalidArgumentException
            ) {
                $this->assertTrue(
                    true
                );
            }
        }

        $this->inTenant(
            $tenant,
            function (): void {
                $this->assertSame(
                    0,
                    CartCreationReceipt::query()
                        ->count(),
                );
            },
        );
    }

    public function test_foreign_or_inactive_app_instance_is_rejected(): void
    {
        $tenantA =
            $this->tenant(
                'Tenant A'
            );

        $tenantB =
            $this->tenant(
                'Tenant B'
            );

        $foreignInstance =
            $this->appInstance(
                $tenantB
            );

        $inactiveInstance =
            $this->appInstance(
                $tenantA,
                false,
            );

        try {
            $this->inTenant(
                $tenantA,
                fn () => $this->service->create(
                    $foreignInstance,
                    'foreign-instance-key',
                ),
            );

            $this->fail(
                'Foreign app instance was accepted.'
            );
        } catch (LogicException) {
            $this->assertTrue(
                true
            );
        }

        try {
            $this->inTenant(
                $tenantA,
                fn () => $this->service->create(
                    $inactiveInstance,
                    'inactive-instance-key',
                ),
            );

            $this->fail(
                'Inactive app instance was accepted.'
            );
        } catch (LogicException) {
            $this->assertTrue(
                true
            );
        }
    }
}
