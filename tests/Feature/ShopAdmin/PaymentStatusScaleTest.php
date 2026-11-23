<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Admin\Models\AdminOrder;
use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopOrderTotal;
use GP247\Shop\Models\ShopPaymentStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopOrders;
use GP247\Shop\Tests\TestCase;

/**
 * Payment status runs on ONE scale: the row ids of shop_payment_status.
 *
 * The defect was invisible from inside either half: the write path used constants
 * numbered 0-3 while every read path used the table numbered 1-4, so a fully paid
 * order displayed "Partial payment", an order awaiting refund displayed "Paid", and
 * an unpaid one was stored as 0 — matching no row at all. A plugin filtering by
 * payment status compared against the wrong id and excluded the wrong orders
 * (RISK-BIZ-payment-status-scale-split).
 *
 * The invariants below are therefore about AGREEMENT, not about one path's output:
 * the constants must agree with the seeded table, and what the order screen shows
 * must agree with what a plugin reading the same order shows.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-payment-status-enum-alignment
 * @aidlc-adr shop-admin_payment-status-enum-alignment
 */
class PaymentStatusScaleTest extends TestCase
{
    use DatabaseTransactions;
    use MakesShopOrders;

    /**
     * Every constant points at a row that exists, and no two point at the same one.
     *
     * Deliberately NOT asserted: the row's NAME. A status name is data the admin edits
     * — this project's own databases already carry localized names — so pinning the
     * English seed text here would fail for a reason that is not a defect, and would
     * contradict the contract it is meant to protect (ADR
     * shop-admin_payment-status-enum-alignment: constants for logic, table for display).
     * What must hold is that the ids resolve.
     */
    public function test_constants_point_at_existing_rows(): void
    {
        $ids = ShopPaymentStatus::pluck('id')->map(fn ($id) => (int) $id)->all();

        foreach (ShopPaymentStatus::businessIds() as $constant) {
            $this->assertContains($constant, $ids, "status id {$constant} must exist in shop_payment_status");
        }
        $this->assertSame(4, count(array_unique(ShopPaymentStatus::businessIds())), 'the four ids must be distinct');
    }

    /**
     * The deprecated aliases must resolve to the same scale — third-party code still
     * uses them, and their old values were exactly the bug.
     */
    public function test_deprecated_aliases_point_at_the_table_ids(): void
    {
        $this->assertSame(ShopPaymentStatus::UNPAID, ShopOrderTotal::NOT_YET_PAY);
        $this->assertSame(ShopPaymentStatus::PARTIAL, ShopOrderTotal::PART_PAY);
        $this->assertSame(ShopPaymentStatus::PAID, ShopOrderTotal::PAID);
        $this->assertSame(ShopPaymentStatus::REFUND, ShopOrderTotal::NEED_REFUND);
        // Guards against a silent revert to the 0-based scale.
        $this->assertSame(1, ShopOrderTotal::NOT_YET_PAY);
    }

    /**
     * Recalculating an order writes an id the status table actually contains — the
     * write path that used to store 0 for "unpaid", which no row matched.
     */
    public function test_recalculation_writes_an_existing_status_id(): void
    {
        $cases = [
            'nothing collected' => [0.0, ShopPaymentStatus::UNPAID],
            'part collected'    => [30.0, ShopPaymentStatus::PARTIAL],
            'paid in full'      => [100.0, ShopPaymentStatus::PAID],
            'overpaid'          => [130.0, ShopPaymentStatus::REFUND],
        ];
        $existing = ShopPaymentStatus::pluck('name', 'id')->all();

        foreach ($cases as $label => [$received, $expected]) {
            $order = $this->makeOrder();
            $this->makeOrderItem($order, ['qty' => 2, 'price' => 50, 'total_price' => 100, 'tax' => 0]);
            AdminOrder::updateSubTotal($order->id);

            // Money moves through the ledger seam, not by writing the column: since the
            // payment ledger, `received` is derived and a direct write is discarded on
            // the next recalculation (ADR shop_order-payment-ledger D2/D3).
            if ($received > 0) {
                ShopOrder::find($order->id)->recordPayment($received, 'CashPayment');
            }
            AdminOrder::updateSubTotal($order->id);

            $stored = (int) ShopOrder::find($order->id)->payment_status;
            $this->assertSame($expected, $stored, $label);
            $this->assertArrayHasKey($stored, $existing, "status stored for '{$label}' must exist in shop_payment_status");
        }
    }

    /**
     * A fully paid order must READ as paid.
     *
     * This is the assertion the defect failed: the status was stored on the 0-3 scale,
     * so resolving it through shop_payment_status returned the row one step below —
     * "Partial payment" for an order with nothing left to collect. Note there is no
     * longer an admin-vs-plugin comparison to make: since D6 both resolve labels from
     * the same table, which is precisely what stops them drifting apart. What is worth
     * asserting is that the stored id resolves at all, and resolves to the right word.
     */
    public function test_a_fully_paid_order_reads_as_paid(): void
    {
        $order = $this->makeOrder();
        $this->makeOrderItem($order, ['qty' => 2, 'price' => 50, 'total_price' => 100, 'tax' => 0]);
        AdminOrder::updateSubTotal($order->id);
        ShopOrder::find($order->id)->recordPayment(100, 'CashPayment');

        $stored = (int) ShopOrder::find($order->id)->payment_status;
        $labels = ShopPaymentStatus::getIdAll();

        $this->assertArrayHasKey($stored, $labels, 'a stored status with no matching row means the scales drifted again');
        // Compare against the row the PAID constant points at — not a hard-coded word,
        // because the label is admin-editable data.
        $this->assertSame(
            $labels[ShopPaymentStatus::PAID],
            $labels[$stored],
            'an order paid in full must read as the paid status, not one step below'
        );
    }

    /**
     * The "cannot delete" list is built from the constants, so the assumption "these
     * four ids mean something" exists in exactly one place instead of two.
     */
    public function test_protected_statuses_are_the_business_ids(): void
    {
        $this->assertSame(
            [ShopPaymentStatus::UNPAID, ShopPaymentStatus::PARTIAL, ShopPaymentStatus::PAID, ShopPaymentStatus::REFUND],
            ShopPaymentStatus::businessIds()
        );
    }
}
