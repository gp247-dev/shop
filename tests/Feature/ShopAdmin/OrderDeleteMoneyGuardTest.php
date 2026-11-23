<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopOrderTransaction;
use GP247\Shop\Models\ShopProduct;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopOrders;
use GP247\Shop\Tests\TestCase;

/**
 * Deleting an order that holds money (audit F21 + F26).
 *
 * Two things are being protected here and they pull in opposite directions, which is
 * why the negative cases matter as much as the positive one:
 *
 *  - An order that took money must not be deletable, because deleting it destroys the
 *    evidence of that money and orphans its ledger rows — rows that stay in the database
 *    but vanish from every report, since revenue and receivables all JOIN back to
 *    shop_order.
 *  - An order that took NO money must stay deletable, and deleting it returns its
 *    goods to stock — the order should never have existed, so neither should its effect
 *    on inventory. Cancelling does the same job explicitly; a marker on the order keeps
 *    the two routes from paying out twice.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-order-delete-money-guard
 * @aidlc-adr shop-admin_order-delete-money-guard
 */
class OrderDeleteMoneyGuardTest extends TestCase
{
    use DatabaseTransactions;
    use MakesShopOrders;

    /**
     * An order of 100 with one line for a real product, so stock effects are visible.
     *
     * @param ShopProduct $product Product the line points at.
     * @return ShopOrder
     */
    private function orderFor(ShopProduct $product): ShopOrder
    {
        $order = $this->makeOrder();
        $this->makeOrderItem($order, [
            'product_id' => $product->id,
            'qty' => 2,
            'price' => 50,
            'total_price' => 100,
            'tax' => 0,
        ]);
        $order->update(['subtotal' => 100, 'total' => 100, 'balance' => 100]);

        return ShopOrder::find($order->id);
    }

    /**
     * The main case: money in the ledger makes the order undeletable.
     */
    public function test_an_order_with_a_ledger_entry_cannot_be_deleted(): void
    {
        $order = $this->orderFor($this->makeProduct());
        $order->recordPayment(40, 'CashPayment');

        ShopOrder::find($order->id)->delete();

        $this->assertNotNull(ShopOrder::find($order->id), 'the order must survive the delete attempt');
        $this->assertSame(1, ShopOrderTransaction::where('order_id', $order->id)->count());
    }

    /**
     * The detail most easily missed: ShopOrderTransaction uses SoftDeletes, so the
     * relation hides trashed rows. Money that was recorded and then withdrawn is still
     * evidence the order handled money, so the guard has to ask withTrashed().
     */
    public function test_a_soft_deleted_ledger_entry_still_blocks_the_delete(): void
    {
        $order = $this->orderFor($this->makeProduct());
        $movement = $order->recordPayment(40, 'CashPayment');
        $movement->delete();
        // received is recalculated to 0, so ONLY the trashed ledger row is left to
        // testify — exactly the state a naive exists() check would wave through.
        ShopOrder::find($order->id)->recalcReceived();

        $fresh = ShopOrder::find($order->id);
        $this->assertEqualsWithDelta(0, (float) $fresh->received, 0.01, 'precondition: no live money');
        $fresh->delete();

        $this->assertNotNull(ShopOrder::find($order->id));
    }

    /**
     * Legacy data: money sits in the column with no ledger row behind it (backfill
     * missed it, or something wrote the column directly).
     */
    public function test_a_received_amount_without_a_ledger_entry_blocks_the_delete(): void
    {
        $order = $this->orderFor($this->makeProduct());
        DB::connection(GP247_DB_CONNECTION)
            ->table(GP247_DB_PREFIX . 'shop_order')
            ->where('id', $order->id)
            ->update(['received' => 25, 'balance' => 75]);

        $this->assertSame(0, ShopOrderTransaction::where('order_id', $order->id)->count(), 'precondition: no ledger');

        ShopOrder::find($order->id)->delete();

        $this->assertNotNull(ShopOrder::find($order->id));
    }

    /**
     * A money-free order is deletable, and deleting it returns its goods.
     */
    public function test_an_order_without_money_is_deleted_and_returns_its_stock(): void
    {
        $product = $this->makeProduct(['stock' => 100]);
        $order = $this->orderFor($product);
        // Creating the line above does not run the storefront's stock deduction, so
        // start from a known figure and assert the delta the delete produces.
        $product->update(['stock' => 98]);

        ShopOrder::find($order->id)->delete();

        $this->assertNull(ShopOrder::find($order->id), 'a money-free order is still deletable');
        $this->assertEqualsWithDelta(
            100,
            (float) ShopProduct::find($product->id)->stock,
            0.01,
            'the goods come back — the order should never have existed'
        );
    }

    /**
     * Ordering inside the deleting hook: the guard has to run BEFORE the restock step.
     * If it ran after, a blocked order would still have handed its quantities back to
     * stock — inventory inflated while the order sits there untouched, and no other
     * assertion in this file would notice.
     */
    public function test_a_blocked_delete_leaves_stock_untouched(): void
    {
        $product = $this->makeProduct(['stock' => 100]);
        $order = $this->orderFor($product);
        $order->recordPayment(40, 'CashPayment');
        $product->update(['stock' => 98]);

        ShopOrder::find($order->id)->delete();

        $this->assertEqualsWithDelta(98, (float) ShopProduct::find($product->id)->stock, 0.01);
    }

    /**
     * Invariant L6 of the ledger domain model, stated the way it can be checked against
     * real production data: no ledger row may point at an order that no longer exists.
     * This is the assertion that would catch a future change to the deleting hook.
     */
    public function test_no_ledger_row_is_left_orphaned(): void
    {
        $order = $this->orderFor($this->makeProduct());
        $order->recordPayment(40, 'CashPayment');
        ShopOrder::find($order->id)->delete();

        $orphans = DB::connection(GP247_DB_CONNECTION)
            ->table(GP247_DB_PREFIX . 'shop_order_transaction as t')
            ->leftJoin(GP247_DB_PREFIX . 'shop_order as o', 'o.id', '=', 't.order_id')
            ->whereNull('o.id')
            ->count();

        $this->assertSame(0, $orphans, 'every ledger row must point at an existing order');
    }

    /**
     * AC7 (QĐ-1=A): the bulk-delete endpoint is gone. It was
     * only reachable from the dead v1 index screen and it reported success even
     * when the model guard had refused — orders are deleted one at a time through
     * OrderManager::delete(), which tells the truth about a refusal.
     */
    public function test_the_bulk_delete_endpoint_is_removed(): void
    {
        $this->assertFalse(
            \Illuminate\Support\Facades\Route::has('admin_order.delete'),
            'the bulk-delete route must not be registered'
        );
        $this->assertFalse(
            method_exists(\GP247\Shop\Admin\Controllers\AdminOrderController::class, 'deleteList'),
            'deleteList() was removed with the dead v1 index screen'
        );
    }
}
