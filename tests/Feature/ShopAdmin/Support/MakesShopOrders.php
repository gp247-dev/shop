<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin\Support;

use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopOrderDetail;
use GP247\Shop\Models\ShopOrderTotal;
use GP247\Shop\Models\ShopProduct;
use GP247\Shop\Models\ShopShippingStatus;

/**
 * Shared fixtures for shop-admin Order (group E) feature tests: build a minimal
 * but consistent order (header + the subtotal/tax/total breakdown rows that
 * AdminOrder::updateSubTotal expects), an order line item, and a throwaway
 * product to add as a line. Domain unchanged (ShopOrder/Detail/Total).
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-003
 * @aidlc-adr ADR-006
 */
trait MakesShopOrders
{
    /**
     * The admin store id the OrderManager scopes to (root store in tests).
     *
     * @return int|string
     */
    private function storeId()
    {
        return session('adminStoreId', defined('GP247_STORE_ID_ROOT') ? GP247_STORE_ID_ROOT : 1);
    }

    /**
     * Create an order in the admin store with the three total breakdown rows
     * (subtotal/tax/total) so recalculation has rows to update.
     *
     * @param array<string, mixed> $overrides Column overrides for the order.
     * @return ShopOrder
     */
    private function makeOrder(array $overrides = []): ShopOrder
    {
        $order = ShopOrder::create(array_merge([
            'customer_id' => 0,
            'currency' => 'USD',
            'exchange_rate' => 1,
            'email' => 'zz-order-' . uniqid() . '@example.com',
            'first_name' => 'ZZ Order',
            'last_name' => 'Buyer',
            'status' => 1,
            'payment_status' => 0,
            'shipping_status' => 1,
            'subtotal' => 0,
            'tax' => 0,
            'shipping' => 0,
            'discount' => 0,
            'other_fee' => 0,
            'total' => 0,
            'received' => 0,
            'balance' => 0,
            'store_id' => $this->storeId(),
        ], $overrides));

        foreach (['subtotal' => 1, 'tax' => 2, 'total' => 100] as $code => $sort) {
            ShopOrderTotal::create([
                'order_id' => $order->id,
                'title' => ucfirst($code),
                'code' => $code,
                'value' => 0,
                'text' => '',
                'sort' => $sort,
            ]);
        }

        return $order;
    }

    /**
     * Attach a line item to an order (defaults: qty 2 @ 50 = 100).
     *
     * @param ShopOrder $order
     * @param array<string, mixed> $overrides
     * @return ShopOrderDetail
     */
    private function makeOrderItem(ShopOrder $order, array $overrides = []): ShopOrderDetail
    {
        return ShopOrderDetail::create(array_merge([
            'order_id' => $order->id,
            'product_id' => 0,
            'name' => 'ZZ Item',
            'sku' => 'ZZ-ITEM',
            'price' => 50,
            'qty' => 2,
            'total_price' => 100,
            'tax' => 0,
            'attribute' => '[]',
            'currency' => $order->currency,
            'exchange_rate' => $order->exchange_rate,
        ], $overrides));
    }

    /**
     * Create a throwaway product (only sku + alias are required by schema).
     *
     * @param array<string, mixed> $overrides
     * @return ShopProduct
     */
    private function makeProduct(array $overrides = []): ShopProduct
    {
        return ShopProduct::create(array_merge([
            'sku' => 'ZZ-SKU-' . uniqid(),
            'alias' => 'zz-prod-' . uniqid(),
            'price' => 30,
            'stock' => 100,
            'status' => 1,
            'kind' => 0,
        ], $overrides));
    }

    /**
     * Two valid shipping-status ids (falls back to [1, 1] if only one exists).
     *
     * @return array{0:int, 1:int}
     */
    private function shippingStatusIds(): array
    {
        $ids = array_keys(ShopShippingStatus::getIdAll());
        $first = (int) ($ids[0] ?? 1);
        $second = (int) ($ids[1] ?? $first);

        return [$first, $second];
    }
}
