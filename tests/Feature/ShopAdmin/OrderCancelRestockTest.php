<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Admin\Livewire\OrderManager;
use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopOrderDetail;
use GP247\Shop\Models\ShopOrderStatus;
use GP247\Shop\Models\ShopShippingStatus;
use GP247\Shop\Models\ShopProduct;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\ConfiguresStockPolicy;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopOrders;
use GP247\Shop\Tests\TestCase;

/**
 * Cancelling returns stock; deleting no longer does.
 *
 * Deleting an order used to be the only documented way to get stock back, so every
 * cancelled order cost a document. Moving that job to the cancel transition is only
 * safe if two things hold, and both are easy to get wrong:
 *
 *  - stock comes back EXACTLY once, no matter which route touches the order (the
 *    cancel-then-delete sequence is the trap);
 *  - re-opening a cancelled order takes the stock back under the shop's own
 *    over-stock policy, all-or-nothing — a half-applied re-open leaves inventory
 *    wrong while the order still shows its old status, with nothing to trace it by.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-order-cancel-restock
 * @aidlc-adr shop-admin_order-cancel-vs-delete
 */
class OrderCancelRestockTest extends TestCase
{
    use ConfiguresStockPolicy;
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use MakesShopOrders;

    /**
     * An order for one product, quantity 2, with stock at a known figure.
     *
     * @param int $stock Stock to leave on the product (already net of this order).
     * @return array{0: ShopOrder, 1: ShopProduct}
     */
    private function orderWithStock(int $stock = 98): array
    {
        $product = $this->makeProduct(['stock' => $stock]);
        $order = $this->makeOrder(['status' => ShopOrderStatus::NEW]);
        $this->makeOrderItem($order, [
            'product_id' => $product->id,
            'qty' => 2,
            'price' => 50,
            'total_price' => 100,
        ]);

        return [ShopOrder::find($order->id), ShopProduct::find($product->id)];
    }

    /**
     * Current stock figure of a product.
     *
     * @param ShopProduct $product
     * @return float
     */
    private function stockOf(ShopProduct $product): float
    {
        return (float) ShopProduct::find($product->id)->stock;
    }

    /**
     * 1 — cancelling puts the order's quantities back.
     */
    public function test_cancelling_returns_stock(): void
    {
        [$order, $product] = $this->orderWithStock(98);

        $order->returnStockToInventory();

        $this->assertEqualsWithDelta(100, $this->stockOf($product), 0.01);
        $this->assertNotNull(ShopOrder::find($order->id)->stock_returned_at);
    }

    /**
     * 2 — cancelling something already cancelled must not pay out twice.
     */
    public function test_cancelling_twice_returns_stock_only_once(): void
    {
        [$order, $product] = $this->orderWithStock(98);

        $order->returnStockToInventory();
        ShopOrder::find($order->id)->returnStockToInventory();

        $this->assertEqualsWithDelta(100, $this->stockOf($product), 0.01);
    }

    /**
     * 3 — the round trip the marker exists for: cancel, re-open, cancel again. Each step
     * has to move the goods exactly once; without the marker the second cancel would
     * hand back stock the order no longer holds.
     */
    public function test_cancel_reopen_cancel_moves_the_goods_once_each_way(): void
    {
        [$order, $product] = $this->orderWithStock(98);

        ShopOrder::find($order->id)->returnStockToInventory();
        $this->assertEqualsWithDelta(100, $this->stockOf($product), 0.01);

        ShopOrder::find($order->id)->takeStockBack();
        $this->assertEqualsWithDelta(98, $this->stockOf($product), 0.01);

        ShopOrder::find($order->id)->returnStockToInventory();
        $this->assertEqualsWithDelta(100, $this->stockOf($product), 0.01);
    }

    /**
     * 4 — re-opening a cancelled order takes the stock back out again.
     */
    public function test_reopening_takes_the_stock_back(): void
    {
        [$order, $product] = $this->orderWithStock(98);
        $order->returnStockToInventory();

        ShopOrder::find($order->id)->takeStockBack();

        $this->assertEqualsWithDelta(98, $this->stockOf($product), 0.01);
        $this->assertNull(ShopOrder::find($order->id)->stock_returned_at);
    }

    /**
     * 5 — re-opening when the goods are no longer available is refused outright, and
     * refused BEFORE anything is written. A partially applied re-open would leave
     * inventory wrong while the order still reads as cancelled.
     */
    public function test_reopening_is_refused_when_stock_is_short(): void
    {
        $this->setProductStock(true);
        $this->setBuyOutOfStock(false);

        [$order, $product] = $this->orderWithStock(98);
        $order->returnStockToInventory();
        // Something else consumed the stock while the order sat cancelled.
        ShopProduct::where('id', $product->id)->update(['stock' => 1]);

        $this->assertFalse(ShopOrder::find($order->id)->takeStockBack());

        $this->assertEqualsWithDelta(1, $this->stockOf($product), 0.01, 'stock untouched');
        $this->assertNotNull(
            ShopOrder::find($order->id)->stock_returned_at,
            'the order still counts as returned-to-stock — nothing was half-applied'
        );
    }

    /**
     * 6 — deletion stays permanent and still takes the children with it.
     *
     * Soft delete was built here and then removed on purpose: with the money guard
     * already refusing any order that holds money, hiding the rest bought little and
     * cost a standing trap — every raw query on shop_order would have had to filter
     * deleted rows for ever after.
     */
    public function test_deleting_removes_the_order_and_its_lines(): void
    {
        [$order, ] = $this->orderWithStock(98);
        // (stock effects of the delete are covered by tests 7 and 7b)
        $orderId = $order->id;

        ShopOrder::find($orderId)->delete();

        $this->assertNull(ShopOrder::find($orderId));
        $this->assertSame(
            0,
            ShopOrderDetail::where('order_id', $orderId)->count(),
            'children go with the parent — otherwise they are orphans nothing can reach'
        );
    }

    /**
     * 7 — deleting an order whose goods are still "out" returns them.
     *
     * Deleting a money-free order means the order should never have existed — a
     * mistake, a test, a duplicate — so its effect on stock should not exist either.
     * Making the delete do this itself is what removes the old trap: an admin who
     * deleted without cancelling first used to lose that stock permanently, with the
     * order gone and nothing left to trace it to.
     */
    public function test_deleting_returns_stock_that_never_came_back(): void
    {
        [$order, $product] = $this->orderWithStock(98);

        ShopOrder::find($order->id)->delete();

        $this->assertEqualsWithDelta(100, $this->stockOf($product), 0.01);
    }

    /**
     * 7b — but deleting an order that was ALREADY cancelled must not return the goods
     * a second time. This is the pairing the marker exists for, now that both routes
     * can move stock again.
     */
    public function test_deleting_a_cancelled_order_does_not_return_stock_twice(): void
    {
        [$order, $product] = $this->orderWithStock(98);
        ShopOrder::find($order->id)->returnStockToInventory();
        $this->assertEqualsWithDelta(100, $this->stockOf($product), 0.01);

        ShopOrder::find($order->id)->delete();

        $this->assertEqualsWithDelta(100, $this->stockOf($product), 0.01);
    }

    /**
     * 7c — an order whose goods have left the building cannot be deleted at all.
     *
     * Without this, "delete returns stock" would quietly credit inventory for goods
     * sitting at a customer's address. And the deeper point: if the shipment really
     * happened, deleting the record is itself the wrong move — the stock error is
     * only the symptom.
     */
    public function test_an_order_that_has_shipped_cannot_be_deleted(): void
    {
        [$order, $product] = $this->orderWithStock(98);
        $order->update(['shipping_status' => ShopShippingStatus::SHIPPING_DONE]);

        ShopOrder::find($order->id)->delete();

        $this->assertNotNull(ShopOrder::find($order->id), 'a shipped order must survive');
        $this->assertEqualsWithDelta(98, $this->stockOf($product), 0.01, 'and its stock is untouched');
    }

    /**
     * 7d — the guard keys on goods having left, not on any shipping value. An order
     * still marked "not sent" is ordinary and stays deletable.
     */
    public function test_an_unsent_order_is_still_deletable(): void
    {
        [$order, $product] = $this->orderWithStock(98);
        $order->update(['shipping_status' => ShopShippingStatus::NOT_SENT]);

        ShopOrder::find($order->id)->delete();

        $this->assertNull(ShopOrder::find($order->id));
        $this->assertEqualsWithDelta(100, $this->stockOf($product), 0.01);
    }

    /**
     * 8a — the real admin action, not just the model method: switching the status
     * dropdown to Canceled returns the stock.
     */
    public function test_changing_status_to_canceled_returns_stock_through_the_screen(): void
    {
        $this->asUser(slugs: ['admin_order']);
        [$order, $product] = $this->orderWithStock(98);

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->call('changeOrderStatus', ShopOrderStatus::CANCELED);

        $this->assertSame(ShopOrderStatus::CANCELED, (int) ShopOrder::find($order->id)->status);
        $this->assertEqualsWithDelta(100, $this->stockOf($product), 0.01);
    }

    /**
     * 8b — and the refusal reaches the screen: when the goods are gone, the status is
     * NOT changed. Writing the status anyway would leave an order reading as live while
     * its stock sat in the warehouse, with nothing to trace the discrepancy to.
     */
    public function test_the_screen_refuses_to_reopen_when_stock_is_short(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $this->setProductStock(true);
        $this->setBuyOutOfStock(false);

        [$order, $product] = $this->orderWithStock(98);
        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->call('changeOrderStatus', ShopOrderStatus::CANCELED);
        ShopProduct::where('id', $product->id)->update(['stock' => 1]);

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->call('changeOrderStatus', ShopOrderStatus::PROCESSING);

        $this->assertSame(
            ShopOrderStatus::CANCELED,
            (int) ShopOrder::find($order->id)->status,
            'the status must not move when the stock move was refused'
        );
        $this->assertEqualsWithDelta(1, $this->stockOf($product), 0.01);
    }

    /**
     * 9 — the money guard still stands: an order that took money is cancelled, never
     * deleted, because deleting it would destroy the evidence of that money.
     */
    public function test_an_order_holding_money_still_cannot_be_deleted(): void
    {
        [$order, ] = $this->orderWithStock(98);
        $order->update(['subtotal' => 100, 'total' => 100, 'balance' => 100]);
        ShopOrder::find($order->id)->recordPayment(40, 'CashPayment');

        ShopOrder::find($order->id)->delete();

        $this->assertNotNull(ShopOrder::find($order->id));
    }

    // -------------------------------------------------------------------------
    // AC9–AC11: every transition path converges on the
    // domain seam ShopOrder::changeStatus() — not just the Livewire screen.
    // -------------------------------------------------------------------------

    /**
     * 10 — the plugin API path: updateStatus() used to write the status column raw,
     * skipping restock entirely. It now carries the same stock semantics.
     */
    public function test_update_status_api_path_returns_stock_on_cancel(): void
    {
        [$order, $product] = $this->orderWithStock(98);

        (new ShopOrder)->updateStatus($order->id, ShopOrderStatus::CANCELED);

        $fresh = ShopOrder::find($order->id);
        $this->assertSame(ShopOrderStatus::CANCELED, (int) $fresh->status);
        $this->assertEqualsWithDelta(100, $this->stockOf($product), 0.01);
        $this->assertNotNull($fresh->stock_returned_at);
    }

    /**
     * 10b — and the refusal holds on that path too: re-opening through the API when
     * the goods are gone must NOT move the status.
     */
    public function test_update_status_api_path_refuses_reopen_when_stock_is_short(): void
    {
        $this->setProductStock(true);
        $this->setBuyOutOfStock(false);

        [$order, $product] = $this->orderWithStock(98);
        (new ShopOrder)->updateStatus($order->id, ShopOrderStatus::CANCELED);
        ShopProduct::where('id', $product->id)->update(['stock' => 1]);

        (new ShopOrder)->updateStatus($order->id, ShopOrderStatus::PROCESSING);

        $this->assertSame(
            ShopOrderStatus::CANCELED,
            (int) ShopOrder::find($order->id)->status,
            'the API path must refuse the transition exactly like the screen does'
        );
        $this->assertEqualsWithDelta(1, $this->stockOf($product), 0.01);
    }

    /**
     * 11 — the gateway path: a customer aborting at the payment gateway cancels the
     * order through cancelOrder(), which used to be a raw status write — the goods
     * of every gateway-cancelled order were lost to stock for good.
     */
    public function test_gateway_cancel_path_returns_stock(): void
    {
        [$order, $product] = $this->orderWithStock(98);
        session(['orderID' => $order->id]);

        (new \GP247\Shop\Controllers\ShopCartController)->cancelOrder();

        $fresh = ShopOrder::find($order->id);
        $this->assertSame(ShopOrderStatus::CANCELED, (int) $fresh->status);
        $this->assertEqualsWithDelta(100, $this->stockOf($product), 0.01);
        $this->assertNotNull($fresh->stock_returned_at);
    }

    /**
     * 12 — the seam leaves an audit trail on every path: cancelling through the
     * domain seam writes a history row (the raw writes left none).
     */
    public function test_change_status_writes_a_history_row(): void
    {
        [$order, ] = $this->orderWithStock(98);

        $result = ShopOrder::find($order->id)->changeStatus(ShopOrderStatus::CANCELED);

        $this->assertNull($result);
        $this->assertSame(
            1,
            \GP247\Shop\Models\ShopOrderHistory::where('order_id', $order->id)
                ->where('order_status_id', ShopOrderStatus::CANCELED)->count()
        );
    }

    /**
     * 12b — a no-op transition (same status in and out) writes nothing: no history
     * noise, no stock movement, no events.
     */
    public function test_change_status_to_the_same_status_is_a_no_op(): void
    {
        [$order, $product] = $this->orderWithStock(98);

        $result = ShopOrder::find($order->id)->changeStatus((int) $order->status);

        $this->assertNull($result);
        $this->assertSame(0, \GP247\Shop\Models\ShopOrderHistory::where('order_id', $order->id)->count());
        $this->assertEqualsWithDelta(98, $this->stockOf($product), 0.01);
    }
}
