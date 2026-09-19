<?php

namespace App\Services\Order;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\InventoryLocation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Sku;
use App\Services\Inventory\InventoryReservationService;
use App\Services\Pricing\CartQuoteService;
use App\Support\Cart\CartStatus;
use App\Support\Order\OrderCheckoutSnapshot;
use App\Support\Order\OrderStatus;
use App\Support\Pricing\ShippingQuote;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class OrderConversionService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CartQuoteService $quotes,
        private readonly InventoryReservationService $reservations,
    ) {}

    public function convert(
        Cart $cart,
        DateTimeInterface $convertedAt,
        DateTimeInterface $requestedQuoteExpiresAt,
        ShippingQuote $shipping,
        OrderCheckoutSnapshot $checkout,
    ): Order {
        $tenantId =
            $this->tenantContext->requireId();

        if (
            (int) $cart->tenant_id !==
            $tenantId
        ) {
            throw new LogicException(
                'Cart must belong to the active tenant.'
            );
        }

        /*
         * The application layer must supply trusted server
         * time explicitly.
         *
         * No hidden now() call determines financial or
         * lifecycle semantics in this conversion.
         */
        $instant =
            CarbonImmutable::instance(
                $convertedAt
            );

        return DB::transaction(
            function () use (
                $cart,
                $tenantId,
                $instant,
                $requestedQuoteExpiresAt,
                $shipping,
                $checkout,
            ): Order {
                /*
                 * Global aggregate lock begins with Cart.
                 */
                $lockedCart =
                    Cart::query()
                        ->whereKey(
                            $cart->id
                        )
                        ->lockForUpdate()
                        ->first();

                if (
                    $lockedCart === null ||
                    (int) $lockedCart->tenant_id
                        !== $tenantId
                ) {
                    throw new LogicException(
                        'Cart is not accessible in the active tenant.'
                    );
                }

                /*
                 * Idempotent replay:
                 *
                 * Once the Cart is converted the persisted
                 * Order is authoritative. Never re-price,
                 * recreate items, or transfer reservations
                 * again.
                 */
                if (
                    $lockedCart->status ===
                    CartStatus::CONVERTED
                ) {
                    $existing =
                        Order::query()
                            ->where(
                                'cart_id',
                                $lockedCart->id,
                            )
                            ->lockForUpdate()
                            ->first();

                    if ($existing === null) {
                        throw new LogicException(
                            'Converted cart has no persisted order.'
                        );
                    }

                    return $existing->load(
                        'items'
                    );
                }

                if (
                    $lockedCart->status !==
                    CartStatus::ACTIVE
                ) {
                    throw new LogicException(
                        'Only an active cart can be converted.'
                    );
                }

                if (
                    $lockedCart->expires_at === null ||
                    ! $lockedCart
                        ->expires_at
                        ->greaterThan(
                            $instant
                        )
                ) {
                    throw new LogicException(
                        'Cart is expired at conversion time.'
                    );
                }

                /*
                 * Conversion is the terminal step of an
                 * inventory-backed checkout.
                 *
                 * The aggregate reservation window must
                 * still cover the exact conversion instant.
                 */
                if (
                    $lockedCart
                        ->inventory_reserved_until
                        === null ||
                    ! $lockedCart
                        ->inventory_reserved_until
                        ->greaterThan(
                            $instant
                        )
                ) {
                    throw new LogicException(
                        'Cart has no active checkout inventory reservation.'
                    );
                }

                /*
                 * An Order must not pre-exist while the Cart
                 * still claims ACTIVE state. Such a state
                 * indicates integrity corruption rather than
                 * an idempotent replay.
                 */
                $preExisting =
                    Order::query()
                        ->where(
                            'cart_id',
                            $lockedCart->id,
                        )
                        ->lockForUpdate()
                        ->first();

                if ($preExisting !== null) {
                    throw new LogicException(
                        'Active cart already has an order.'
                    );
                }

                /*
                 * Re-price inside THIS transaction.
                 *
                 * No client-roundtripped CheckoutQuote is
                 * accepted as authority.
                 *
                 * CartQuoteService locks:
                 *
                 * Cart
                 * -> CartItems
                 * -> SKUs
                 * -> Locations
                 * -> Products
                 * -> exact SkuPrice rows
                 *
                 * Those locks remain held until this outer
                 * conversion transaction commits.
                 */
                $quote =
                    $this->quotes->quote(
                        $lockedCart,
                        $instant,
                        $requestedQuoteExpiresAt,
                        $shipping,
                    );

                if (
                    $quote->cartPublicId !==
                    $lockedCart->public_id
                ) {
                    throw new LogicException(
                        'Quote does not belong to the locked cart.'
                    );
                }

                if (
                    ! $quote->isUsableAt(
                        $instant
                    )
                ) {
                    throw new LogicException(
                        'Quote is not usable at conversion time.'
                    );
                }

                /*
                 * Re-read CartItems while the quote locks
                 * are still held.
                 */
                $cartItems =
                    CartItem::query()
                        ->where(
                            'cart_id',
                            $lockedCart->id,
                        )
                        ->orderBy(
                            'location_id'
                        )
                        ->orderBy(
                            'sku_id'
                        )
                        ->orderBy(
                            'id'
                        )
                        ->lockForUpdate()
                        ->get();

                if (
                    $cartItems->count() !==
                    count($quote->lines)
                ) {
                    throw new LogicException(
                        'Cart and quote line counts diverged.'
                    );
                }

                $itemsByPublicId =
                    $cartItems->keyBy(
                        'public_id'
                    );

                $skuIds =
                    collect(
                        $quote->lines
                    )
                        ->map(
                            fn ($line): int => $line->skuId
                        )
                        ->unique()
                        ->sort()
                        ->values()
                        ->all();

                $locationIds =
                    collect(
                        $quote->lines
                    )
                        ->map(
                            fn ($line): int => $line->locationId
                        )
                        ->unique()
                        ->sort()
                        ->values()
                        ->all();

                /*
                 * These rows were already locked by the
                 * authoritative quote operation.
                 */
                $skus =
                    Sku::query()
                        ->whereIn(
                            'id',
                            $skuIds,
                        )
                        ->orderBy('id')
                        ->get()
                        ->keyBy('id');

                $locations =
                    InventoryLocation::query()
                        ->whereIn(
                            'id',
                            $locationIds,
                        )
                        ->orderBy('id')
                        ->get()
                        ->keyBy('id');

                if (
                    $skus->count() !==
                        count($skuIds) ||
                    $locations->count() !==
                        count($locationIds)
                ) {
                    throw new LogicException(
                        'Order inventory references are unavailable.'
                    );
                }

                $productIds =
                    $skus
                        ->pluck(
                            'product_id'
                        )
                        ->map(
                            fn ($id): int => (int) $id
                        )
                        ->unique()
                        ->sort()
                        ->values()
                        ->all();

                $products =
                    Product::query()
                        ->whereIn(
                            'id',
                            $productIds,
                        )
                        ->orderBy('id')
                        ->get()
                        ->keyBy('id');

                if (
                    $products->count() !==
                    count($productIds)
                ) {
                    throw new LogicException(
                        'Order product references are unavailable.'
                    );
                }

                /*
                 * Financial header snapshot.
                 *
                 * shipping_minor is quote shipping NET.
                 * Shipping VAT is already included in
                 * quote taxMinor, preserving the persisted
                 * Order equation.
                 */
                $order =
                    Order::query()
                        ->create([
                            'app_instance_id' => $lockedCart
                                ->app_instance_id,

                            'customer_id' => $checkout
                                ->customerId,

                            'cart_id' => $lockedCart->id,

                            'public_id' => (string)
                                Str::uuid(),

                            'status' => OrderStatus::PENDING,

                            'currency_code' => $quote
                                ->currencyCode,

                            'customer_name' => $checkout
                                ->customerName,

                            'customer_phone' => $checkout
                                ->customerPhone,

                            'customer_email' => $checkout
                                ->customerEmail,

                            'shipping_address_snapshot' => $checkout
                                ->shippingAddressSnapshot,

                            'subtotal_minor' => $quote
                                ->subtotalMinor,

                            'discount_minor' => $quote
                                ->discountMinor,

                            'tax_minor' => $quote
                                ->taxMinor,

                            'shipping_minor' => $quote
                                ->shippingMinor,

                            'total_minor' => $quote
                                ->totalMinor,
                        ]);

                foreach (
                    $quote->lines as $line
                ) {
                    $cartItem =
                        $itemsByPublicId->get(
                            $line
                                ->cartItemPublicId
                        );

                    if ($cartItem === null) {
                        throw new LogicException(
                            'Quoted cart item no longer exists.'
                        );
                    }

                    if (
                        (int) $cartItem->sku_id
                            !== $line->skuId ||
                        (int) $cartItem
                            ->location_id
                            !== $line->locationId ||
                        (int) $cartItem->quantity
                            !== $line->quantity
                    ) {
                        throw new LogicException(
                            'Quoted cart item changed before conversion.'
                        );
                    }

                    $sku =
                        $skus->get(
                            $line->skuId
                        );

                    $location =
                        $locations->get(
                            $line->locationId
                        );

                    if (
                        $sku === null ||
                        $location === null
                    ) {
                        throw new LogicException(
                            'Order inventory reference disappeared.'
                        );
                    }

                    $product =
                        $products->get(
                            $sku->product_id
                        );

                    if ($product === null) {
                        throw new LogicException(
                            'Order product reference disappeared.'
                        );
                    }

                    $orderItemPublicId =
                        (string)
                        Str::uuid();

                    /*
                     * Catalog and financial snapshots are
                     * persisted before ownership transfer.
                     *
                     * If transfer fails, the outer
                     * transaction removes the OrderItem
                     * automatically.
                     */
                    $orderItem =
                        OrderItem::query()
                            ->create([
                                'order_id' => $order->id,

                                'sku_id' => $sku->id,

                                'location_id' => $location->id,

                                'public_id' => $orderItemPublicId,

                                'sku_code_snapshot' => $sku->code,

                                'barcode_snapshot' => $sku->barcode,

                                'product_name_ar_snapshot' => $product
                                    ->name_ar,

                                'product_name_en_snapshot' => $product
                                    ->name_en,

                                'sku_name_ar_snapshot' => $sku->name_ar,

                                'sku_name_en_snapshot' => $sku->name_en,

                                'quantity' => $line->quantity,

                                'unit_net_minor' => $line
                                    ->unitNetMinor,

                                'line_subtotal_minor' => $line
                                    ->lineSubtotalMinor,

                                'discount_minor' => $line
                                    ->discountMinor,

                                'tax_rate_bps' => $line
                                    ->taxRateBps,

                                'tax_minor' => $line
                                    ->taxMinor,

                                'line_total_minor' => $line
                                    ->lineTotalMinor,
                            ]);

                    /*
                     * Reservation stays ACTIVE.
                     *
                     * Only ownership moves:
                     *
                     * cart_item:<cart item UUID>
                     *       ->
                     * order_item:<order item UUID>
                     */
                    $this->reservations
                        ->transferReferenceOwnership(
                            $sku,
                            $location,
                            $line->quantity,
                            'cart_item',
                            $cartItem
                                ->public_id,
                            'order_item',
                            $orderItem
                                ->public_id,
                            $instant,
                        );
                }

                /*
                 * Cart ownership ends only after EVERY
                 * OrderItem and reservation transfer has
                 * succeeded.
                 */
                $lockedCart->status =
                    CartStatus::CONVERTED;

                $lockedCart->converted_at =
                    $instant;

                $lockedCart
                    ->inventory_reserved_until =
                        null;

                $lockedCart->save();

                return $order->load(
                    'items'
                );
            }
        );
    }
}
