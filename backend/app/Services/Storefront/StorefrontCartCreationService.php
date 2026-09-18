<?php

namespace App\Services\Storefront;

use App\Exceptions\Cart\CartIdempotencyConflictException;
use App\Models\AppInstance;
use App\Models\Cart;
use App\Models\CartCreationReceipt;
use App\Services\Cart\CartService;
use App\Support\Cart\CreatedCart;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

final class StorefrontCartCreationService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CartService $carts,
    ) {}

    public function create(
        AppInstance $appInstance,
        string $idempotencyKey,
    ): CreatedCart {
        $tenantId =
            $this->tenantContext->requireId();

        $idempotencyKey =
            $this->normalizeIdempotencyKey(
                $idempotencyKey
            );

        if (
            (int) $appInstance->tenant_id !==
            $tenantId
        ) {
            throw new LogicException(
                'App instance must belong to the active tenant.'
            );
        }

        $token = $this->deriveToken(
            $tenantId,
            (int) $appInstance->id,
            $idempotencyKey,
        );

        $requestHash = hash(
            'sha256',
            sprintf(
                'v1|create_cart|tenant=%d|app_instance=%d',
                $tenantId,
                (int) $appInstance->id,
            ),
        );

        $ttlDays =
            $this->cartTtlDays();

        return DB::transaction(
            function () use (
                $appInstance,
                $tenantId,
                $idempotencyKey,
                $token,
                $requestHash,
                $ttlDays,
            ): CreatedCart {
                $lockedInstance =
                    AppInstance::query()
                        ->whereKey(
                            $appInstance->id
                        )
                        ->lockForUpdate()
                        ->first();

                if (
                    $lockedInstance === null ||
                    (int) $lockedInstance->tenant_id !==
                        $tenantId ||
                    ! $lockedInstance->is_active
                ) {
                    throw new LogicException(
                        'App instance is not available.'
                    );
                }

                $receipt =
                    CartCreationReceipt::query()
                        ->where(
                            'app_instance_id',
                            $lockedInstance->id,
                        )
                        ->where(
                            'idempotency_key',
                            $idempotencyKey,
                        )
                        ->lockForUpdate()
                        ->first();

                if ($receipt !== null) {
                    return $this->replay(
                        $receipt,
                        $lockedInstance,
                        $requestHash,
                        $token,
                    );
                }

                $created =
                    $this->carts->create(
                        $lockedInstance,
                        now()->addDays(
                            $ttlDays
                        ),
                        $token,
                    );

                CartCreationReceipt::query()
                    ->create([
                        'app_instance_id' => $lockedInstance->id,

                        'cart_id' => $created->cart->id,

                        'idempotency_key' => $idempotencyKey,

                        'request_hash' => $requestHash,
                    ]);

                return $created;
            }
        );
    }

    private function replay(
        CartCreationReceipt $receipt,
        AppInstance $instance,
        string $requestHash,
        string $token,
    ): CreatedCart {
        if (
            ! hash_equals(
                $receipt->request_hash,
                $requestHash,
            )
        ) {
            throw new CartIdempotencyConflictException;
        }

        $cart =
            Cart::query()
                ->whereKey(
                    $receipt->cart_id
                )
                ->first();

        if (
            $cart === null ||
            (int) $cart->app_instance_id !==
                (int) $instance->id
        ) {
            throw new LogicException(
                'Idempotent cart creation result is unavailable.'
            );
        }

        if (
            ! hash_equals(
                (string) $cart->token_hash,
                hash(
                    'sha256',
                    $token,
                ),
            )
        ) {
            throw new LogicException(
                'Idempotent cart creation token cannot be reproduced.'
            );
        }

        return new CreatedCart(
            cart: $cart,
            token: $token,
        );
    }

    private function normalizeIdempotencyKey(
        string $key,
    ): string {
        $key = trim($key);

        if (
            $key === '' ||
            mb_strlen($key) > 120
        ) {
            throw new InvalidArgumentException(
                'Invalid cart creation idempotency key.'
            );
        }

        return $key;
    }

    private function deriveToken(
        int $tenantId,
        int $appInstanceId,
        string $idempotencyKey,
    ): string {
        $key = $this->applicationKey();

        return hash_hmac(
            'sha256',
            sprintf(
                'storefront-cart-v1|tenant=%d|app_instance=%d|key=%s',
                $tenantId,
                $appInstanceId,
                $idempotencyKey,
            ),
            $key,
        );
    }

    private function applicationKey(): string
    {
        $configured =
            config('app.key');

        if (
            ! is_string($configured) ||
            trim($configured) === ''
        ) {
            throw new RuntimeException(
                'APP_KEY is required for storefront cart creation.'
            );
        }

        $configured = trim(
            $configured
        );

        if (
            str_starts_with(
                $configured,
                'base64:',
            )
        ) {
            $decoded = base64_decode(
                substr(
                    $configured,
                    7,
                ),
                true,
            );

            if (
                $decoded === false ||
                strlen($decoded) < 32
            ) {
                throw new RuntimeException(
                    'APP_KEY is invalid for storefront cart creation.'
                );
            }

            return $decoded;
        }

        if (
            strlen($configured) < 32
        ) {
            throw new RuntimeException(
                'APP_KEY is too short for storefront cart creation.'
            );
        }

        return $configured;
    }

    private function cartTtlDays(): int
    {
        $days = (int) config(
            'platform.storefront_cart_ttl_days',
            30,
        );

        if (
            $days < 1 ||
            $days > 365
        ) {
            throw new RuntimeException(
                'Storefront cart TTL must be between 1 and 365 days.'
            );
        }

        return $days;
    }
}
