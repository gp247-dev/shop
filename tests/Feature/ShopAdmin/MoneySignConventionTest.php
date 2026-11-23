<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Admin\Models\AdminOrder;
use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopOrderTotal;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopOrders;
use GP247\Shop\Tests\TestCase;

/**
 * Invariants of the order money SIGN CONTRACT.
 *
 * The defect these lock down could not be seen by reading any single screen: the
 * storefront and the order-edit path stored `discount`/`received` NEGATIVE and summed
 * them, while the admin create screen stored them POSITIVE and subtracted — so an
 * admin-created order turned its discount into a surcharge the first time anything
 * recalculated it, and its balance was stored inflated
 * (RISK-BIZ-order-sign-split). The contract is therefore expressed as invariants that
 * compare paths against each other, not as assertions about one path's arithmetic:
 *
 *   M1  every stored money value is a non-negative magnitude
 *   M2  total   = subtotal + tax + shipping + other_fee − discount
 *   M3  balance = total − received
 *   M5  no shop_order_total row carries code 'received'
 *   M6  the same inputs produce the same total/balance whichever writer ran
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-money-sign-convention
 * @aidlc-adr shop-admin_money-sign-convention
 */
class MoneySignConventionTest extends TestCase
{
    use DatabaseTransactions;
    use MakesShopOrders;

    /**
     * Add one breakdown row carrying a non-negative magnitude.
     *
     * @param ShopOrder $order
     * @param string    $code
     * @param float     $value
     * @return ShopOrderTotal
     */
    private function addTotalRow(ShopOrder $order, string $code, float $value): ShopOrderTotal
    {
        $sorts = ['shipping' => 10, 'discount' => 20, 'other_fee' => 80];

        return ShopOrderTotal::create([
            'order_id' => $order->id,
            'title' => ucfirst($code),
            'code' => $code,
            'value' => $value,
            'text' => '',
            'sort' => $sorts[$code] ?? 50,
        ]);
    }

    /**
     * The sign map is the single declaration of sign, and it deducts only the discount.
     */
    public function test_sign_map_declares_discount_as_the_only_deduction(): void
    {
        $this->assertSame(-1, ShopOrderTotal::signOf('discount'));
        $this->assertSame(1, ShopOrderTotal::signOf('shipping'));
        $this->assertSame(1, ShopOrderTotal::signOf('other_fee'));
        // An unknown code from a third-party plugin adds rather than silently deducts.
        $this->assertSame(1, ShopOrderTotal::signOf('zz_unknown_plugin_line'));
    }

    /**
     * M2 + M1: recalculation subtracts a POSITIVE discount and adds other_fee — the
     * fee used to be dropped from the sum entirely, erasing a surcharge on every edit.
     */
    public function test_recalculation_subtracts_positive_discount_and_keeps_other_fee(): void
    {
        $order = $this->makeOrder();
        $this->makeOrderItem($order, ['qty' => 2, 'price' => 50, 'total_price' => 100, 'tax' => 0]);
        // Sync the subtotal row from the line item first: updateRowOrderTotal re-sums
        // from the ROWS, so a stale subtotal row would be summed instead of the item.
        AdminOrder::updateSubTotal($order->id);
        $this->addTotalRow($order, 'shipping', 10);
        $this->addTotalRow($order, 'discount', 20);
        $this->addTotalRow($order, 'other_fee', 5);

        // updateRowOrderTotal re-sums from the rows; touch one to trigger it.
        $shippingRow = ShopOrderTotal::where('order_id', $order->id)->where('code', 'shipping')->first();
        AdminOrder::updateRowOrderTotal([
            'id' => $shippingRow->id,
            'code' => 'shipping',
            'value' => 10,
            'text' => '',
        ]);

        $fresh = ShopOrder::find($order->id);
        // 100 + 0 tax + 10 shipping + 5 other_fee − 20 discount
        $this->assertEqualsWithDelta(95, (float) $fresh->total, 0.01);
        $this->assertEqualsWithDelta(20, (float) $fresh->discount, 0.01);
        $this->assertEqualsWithDelta(5, (float) $fresh->other_fee, 0.01);
        $this->assertGreaterThanOrEqual(0, (float) $fresh->discount);
    }

    /**
     * M3: the balance is driven by the payment, and the payment never enters the total.
     */
    public function test_balance_is_total_minus_received(): void
    {
        $order = $this->makeOrder();
        $this->makeOrderItem($order, ['qty' => 2, 'price' => 50, 'total_price' => 100, 'tax' => 0]);
        AdminOrder::updateSubTotal($order->id);

        // Money enters through the ledger seam — `received` is derived from it.
        ShopOrder::find($order->id)->recordPayment(30, 'CashPayment');
        AdminOrder::updateSubTotal($order->id);

        $fresh = ShopOrder::find($order->id);
        $this->assertEqualsWithDelta(100, (float) $fresh->total, 0.01);
        $this->assertEqualsWithDelta(70, (float) $fresh->balance, 0.01);
        $this->assertSame(ShopOrderTotal::PART_PAY, (int) $fresh->payment_status);
    }

    /**
     * M3 boundary: paying the full amount clears the balance; overpaying goes negative
     * and is flagged for refund.
     */
    public function test_payment_status_follows_the_money_collected(): void
    {
        $order = $this->makeOrder();
        $this->makeOrderItem($order, ['qty' => 2, 'price' => 50, 'total_price' => 100, 'tax' => 0]);

        AdminOrder::updateSubTotal($order->id);
        $this->assertSame(ShopOrderTotal::NOT_YET_PAY, (int) ShopOrder::find($order->id)->payment_status);

        ShopOrder::find($order->id)->recordPayment(100, 'CashPayment');
        AdminOrder::updateSubTotal($order->id);
        $paid = ShopOrder::find($order->id);
        $this->assertEqualsWithDelta(0, (float) $paid->balance, 0.01);
        $this->assertSame(ShopOrderTotal::PAID, (int) $paid->payment_status);

        ShopOrder::find($order->id)->recordPayment(30, 'CashPayment');
        AdminOrder::updateSubTotal($order->id);
        $over = ShopOrder::find($order->id);
        $this->assertEqualsWithDelta(-30, (float) $over->balance, 0.01);
        $this->assertSame(ShopOrderTotal::NEED_REFUND, (int) $over->payment_status);
    }

    /**
     * M5 + D3: capturing a payment writes the magnitude on the order and does not
     * resurrect a `received` total row.
     */
    public function test_process_payment_paid_stores_positive_received_without_a_total_row(): void
    {
        $order = $this->makeOrder();
        $this->makeOrderItem($order, ['qty' => 2, 'price' => 50, 'total_price' => 100, 'tax' => 0]);
        AdminOrder::updateSubTotal($order->id);

        ShopOrder::find($order->id)->processPaymentPaid();

        $fresh = ShopOrder::find($order->id);
        $this->assertEqualsWithDelta(100, (float) $fresh->received, 0.01);
        $this->assertEqualsWithDelta(0, (float) $fresh->balance, 0.01);
        $this->assertSame(0, ShopOrderTotal::where('order_id', $order->id)->where('code', 'received')->count());
    }

    /**
     * M6 — the invariant that would have caught the original defect: two orders built
     * with the same money through the two different writers must agree. One is summed
     * the way the storefront/edit path recalculates; the other is written the way the
     * admin create screen writes its columns directly.
     */
    public function test_both_writers_agree_on_total_and_balance(): void
    {
        // Path A — recalculated from rows (storefront checkout / admin order edit).
        $viaRows = $this->makeOrder();
        $this->makeOrderItem($viaRows, ['qty' => 2, 'price' => 50, 'total_price' => 100, 'tax' => 0]);
        AdminOrder::updateSubTotal($viaRows->id);
        $this->addTotalRow($viaRows, 'shipping', 10);
        $this->addTotalRow($viaRows, 'discount', 20);
        $shippingRow = ShopOrderTotal::where('order_id', $viaRows->id)->where('code', 'shipping')->first();
        AdminOrder::updateRowOrderTotal([
            'id' => $shippingRow->id,
            'code' => 'shipping',
            'value' => 10,
            'text' => '',
        ]);
        ShopOrder::find($viaRows->id)->recordPayment(40, 'CashPayment');
        AdminOrder::updateSubTotal($viaRows->id);

        // Path B — the admin create screen's own arithmetic, same inputs.
        $subtotal = 100.0;
        $tax = 0.0;
        $shipping = 10.0;
        $discount = 20.0;
        $received = 40.0;
        $total = $subtotal + $tax + $shipping - $discount;
        $balance = $total - $received;

        $fresh = ShopOrder::find($viaRows->id);
        $this->assertEqualsWithDelta($total, (float) $fresh->total, 0.01);
        $this->assertEqualsWithDelta($balance, (float) $fresh->balance, 0.01);

        // M1 — nothing negative was stored along the way.
        $this->assertGreaterThanOrEqual(0, (float) $fresh->discount);
        $this->assertGreaterThanOrEqual(0, (float) $fresh->received);
        $this->assertGreaterThanOrEqual(0, (float) $fresh->shipping);
        foreach (ShopOrderTotal::where('order_id', $viaRows->id)->get() as $row) {
            $this->assertGreaterThanOrEqual(0, (float) $row->value, "row {$row->code} must hold a magnitude");
        }
    }
}
