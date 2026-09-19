<?php

namespace App\Services\Storefront;

use App\Exceptions\Order\OrderAccessTokenConflictException;
use App\Exceptions\Order\OrderNotAccessibleException;
use App\Models\AppInstance;
use App\Models\Order;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class StorefrontOrderService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function bindGuestAccess(
        Order $order,
        string $token,
    ): Order {
        $tenantId =
            $this->tenantContext->requireId();

        if (
            (int) $order->tenant_id !==
            $tenantId
        ) {
            throw new OrderNotAccessibleException;
        }
        $tokenHash =
            $this->hashToken(
                $this->normalizeToken($token)
            );

        return DB::transaction(
            function () use (
                $order,
                $tenantId,
                $tokenHash,
            ): Order {
                $locked =
                    Order::query()
                        ->whereKey($order->id)
                        ->lockForUpdate()
                        ->first();

                if (
                    $locked === null ||
                    (int) $locked->tenant_id !==
                    $tenantId
                ) {
                    throw new OrderNotAccessibleException;
                }

                $existing = trim(
                    (string)
                    $locked->guest_access_token_hash
                );
                if ($existing === '') {
                    $locked->guest_access_token_hash =
                        $tokenHash;

                    $locked->save();
                } elseif (! hash_equals(
                    $existing,
                    $tokenHash,
                )) {
                    throw new OrderAccessTokenConflictException;
                }

                return $locked->load([
                    'items',
                    'payment',
                ]);
            }
        );
    }

    public function resolveGuest(
        AppInstance $instance,
        string $orderPublicId,
        string $token,
    ): Order {
        $tenantId =
            $this->tenantContext->requireId();

        if (
            (int) $instance->tenant_id !==
            $tenantId
        ) {
            throw new OrderNotAccessibleException;
        }
        try {
            $tokenHash =
                $this->hashToken(
                    $this->normalizeToken($token)
                );
        } catch (InvalidArgumentException) {
            throw new OrderNotAccessibleException;
        }

        $order =
            Order::query()
                ->where(
                    'public_id',
                    trim($orderPublicId),
                )
                ->where(
                    'app_instance_id',
                    $instance->id,
                )
                ->first();

        if ($order === null) {
            throw new OrderNotAccessibleException;
        }

        $storedHash = trim(
            (string)
            $order->guest_access_token_hash
        );

        if (
            $storedHash === '' ||
            ! hash_equals(
                $storedHash,
                $tokenHash,
            )
        ) {
            throw new OrderNotAccessibleException;
        }

        return $order->load([
            'items',
            'payment',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function present(
        Order $order,
    ): array {
        $order->loadMissing([
            'items',
            'payment',
        ]);

        $payment = $order->payment;

        return [
            'order' => [
                'id' => $order->public_id,
                'status' => $order->status,
                'currency_code' => $order->currency_code,
                'subtotal_minor' => (int) $order->subtotal_minor,
                'discount_minor' => (int) $order->discount_minor,
                'tax_minor' => (int) $order->tax_minor,
                'shipping_minor' => (int) $order->shipping_minor,
                'total_minor' => (int) $order->total_minor,
                'customer_name' => $order->customer_name,
                'customer_phone' => $order->customer_phone,
                'customer_email' => $order->customer_email,
                'shipping_address' => $order
                    ->shipping_address_snapshot,
                'created_at' => $order->created_at
                    ?->toISOString(),
                'confirmed_at' => $order->confirmed_at
                    ?->toISOString(),
                'items' => $order->items
                    ->map(
                        static fn ($item): array => [
                            'id' => $item->public_id,
                            'sku_id' => (string) $item->sku_id,
                            'location_id' => (string)
                                $item->location_id,
                            'sku_code' => $item
                                ->sku_code_snapshot,
                            'product_name_ar' => $item
                                ->product_name_ar_snapshot,
                            'product_name_en' => $item
                                ->product_name_en_snapshot,
                            'quantity' => (int) $item->quantity,
                            'unit_net_minor' => (int)
                                $item->unit_net_minor,
                            'tax_minor' => (int) $item->tax_minor,
                            'line_total_minor' => (int)
                                $item->line_total_minor,
                        ]
                    )
                    ->values()
                    ->all(),
            ],
            'payment' => $payment === null
                ? null
                : [
                    'id' => $payment->public_id,
                    'status' => $payment->status,
                    'currency_code' => $payment->currency_code,
                    'amount_minor' => (int)
                        $payment->amount_minor,
                ],
        ];
    }

    private function normalizeToken(
        string $token,
    ): string {
        $normalized = strtolower(
            trim($token)
        );
        if (
            preg_match(
                '/^[0-9a-f]{64}$/',
                $normalized,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Order access token must contain exactly 64 hexadecimal characters.'
            );
        }

        return $normalized;
    }

    private function hashToken(
        string $token,
    ): string {
        return hash(
            'sha256',
            $token,
        );
    }
}
