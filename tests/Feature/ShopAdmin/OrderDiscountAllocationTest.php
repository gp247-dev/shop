<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Admin\Models\AdminOrder;
use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopOrderDetail;
use GP247\Shop\Models\ShopOrderTotal;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopOrders;
use GP247\Shop\Tests\TestCase;

/**
 * Discount before tax, allocated to lines (audit F5 + F6).
 *
 * The change is easy to state and easy to get subtly wrong. Two invariants carry it:
 * the per-line shares must sum to the order discount EXACTLY (not nearly), and the
 * allocation must be recomputed from scratch every time — because updateSubTotal() runs
 * on every line edit, and an allocation that reads its own previous output drifts a
 * little further with each save, silently (RISK-TECH-discount-allocation-drift).
 *
 * The mixed-rate cases are the ones that justify per-line allocation at all: with one
 * tax rate you could fudge the order total, with two you cannot.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-order-discount-pre-tax
 * @aidlc-adr shop-admin_order-discount-pre-tax
 */
class OrderDiscountAllocationTest extends TestCase
{
    use DatabaseTransactions;
    use MakesShopOrders;

    /**
     * Build an order with the given lines and an order-level discount.
     *
     * @param array<int, array{price: float, qty: float, rate: float}> $lines
     * @param float $discount Order-level discount magnitude.
     * @return ShopOrder
     */
    private function orderWith(array $lines, float $discount): ShopOrder
    {
        $order = $this->makeOrder(['discount' => $discount]);
        foreach ($lines as $i => $line) {
            $this->makeOrderItem($order, [
                // Labelled because the allocation order is (created_at, id) and these
                // rows share a timestamp — identifying a line by position would make the
                // assertions depend on which uuid happened to sort first.
                'name' => 'L' . $i,
                'qty' => $line['qty'],
                'price' => $line['price'],
                'total_price' => $line['price'] * $line['qty'],
                'tax' => 0,
                'tax_rate' => $line['rate'],
            ]);
        }
        ShopOrderTotal::where('order_id', $order->id)->where('code', 'discount')->delete();
        ShopOrderTotal::create([
            'order_id' => $order->id,
            'title' => 'Discount',
            'code' => 'discount',
            'value' => $discount,
            'text' => '',
            // Discount is a "total method" row (the slot coupon plugins occupy).
            'sort' => ShopOrderTotal::POSITION_TOTAL_METHOD,
        ]);

        return ShopOrder::find($order->id);
    }

    /**
     * Per-line discounts of the order, ordered by insertion.
     *
     * @param ShopOrder $order
     * @return array<int, float>
     */
    private function lineDiscounts(ShopOrder $order): array
    {
        return ShopOrderDetail::where('order_id', $order->id)
            ->pluck('discount', 'name')->map(fn ($v) => (float) $v)->all();
    }

    /**
     * Per-line taxes keyed by the line label.
     *
     * @param ShopOrder $order
     * @return array<string, float>
     */
    private function lineTaxes(ShopOrder $order): array
    {
        return ShopOrderDetail::where('order_id', $order->id)
            ->pluck('tax', 'name')->map(fn ($v) => (float) $v)->all();
    }

    /**
     * 1 — the shares add up to the order discount exactly, even when the split does
     * not divide evenly. "Nearly" is not good enough: every downstream reconciliation
     * compares these two numbers.
     */
    public function test_line_shares_sum_exactly_to_the_order_discount(): void
    {
        $order = $this->orderWith([
            ['price' => 100, 'qty' => 1, 'rate' => 0],
            ['price' => 100, 'qty' => 1, 'rate' => 0],
            ['price' => 100, 'qty' => 1, 'rate' => 0],
        ], 10);

        $order->reallocateDiscountAndTax();

        $shares = array_values($this->lineDiscounts(ShopOrder::find($order->id)));
        $this->assertEqualsWithDelta(10.00, array_sum($shares), 0.001, 'shares must total the order discount');
        // 10 / 3 = 3.333…, so two lines get 3.33 and exactly one absorbs the remainder.
        // WHICH line absorbs it is settled by the allocation order and is not the point;
        // that the shares still add up exactly is.
        sort($shares);
        $this->assertEqualsWithDelta([3.33, 3.33, 3.34], $shares, 0.001);
    }

    /**
     * 2 — tax is charged on what the customer actually pays for each line, and each
     * line uses its own rate. This is the whole point of F5.
     */
    public function test_tax_is_computed_on_the_discounted_base_per_rate(): void
    {
        // 200 @10%, 300 @10%, 100 @5%; subtotal 600, discount 60 (10%)
        $order = $this->orderWith([
            ['price' => 200, 'qty' => 1, 'rate' => 10],
            ['price' => 150, 'qty' => 2, 'rate' => 10],
            ['price' => 100, 'qty' => 1, 'rate' => 5],
        ], 60);

        $order->reallocateDiscountAndTax();

        $taxes = $this->lineTaxes(ShopOrder::find($order->id));
        $this->assertEqualsWithDelta(18.00, $taxes['L0'], 0.01, '(200-20) x 10%');
        $this->assertEqualsWithDelta(27.00, $taxes['L1'], 0.01, '(300-30) x 10%');
        $this->assertEqualsWithDelta(4.50, $taxes['L2'], 0.01, '(100-10) x 5%');

        AdminOrder::updateSubTotal($order->id);
        $fresh = ShopOrder::find($order->id);
        $this->assertEqualsWithDelta(49.50, (float) $fresh->tax, 0.01, 'order tax = sum of line taxes');
        $this->assertEqualsWithDelta(589.50, (float) $fresh->total, 0.01, '600 + 49.50 - 60');
    }

    /**
     * 3 — the drift guard. updateSubTotal() runs on every line edit, so recomputing has
     * to be idempotent. An allocation that fed on its own output would creep on each
     * save without raising anything.
     */
    public function test_recomputing_three_times_changes_nothing(): void
    {
        $order = $this->orderWith([
            ['price' => 33.33, 'qty' => 1, 'rate' => 8],
            ['price' => 11.11, 'qty' => 3, 'rate' => 8],
            ['price' => 7.77, 'qty' => 1, 'rate' => 8],
        ], 24.56);

        AdminOrder::updateSubTotal($order->id);
        $first = ShopOrderDetail::where('order_id', $order->id)->orderBy('id')
            ->get(['discount', 'tax'])->toArray();

        AdminOrder::updateSubTotal($order->id);
        AdminOrder::updateSubTotal($order->id);
        $third = ShopOrderDetail::where('order_id', $order->id)->orderBy('id')
            ->get(['discount', 'tax'])->toArray();

        $this->assertSame($first, $third, 'allocation must be recomputed, never accumulated');
    }

    /**
     * 4 — editing the DISCOUNT TOTAL ROW must reach the lines. Before this change
     * updateRowOrderTotal() never touched shop_order_detail at all, which is exactly
     * how the invariant would have broken on the most ordinary admin action.
     */
    public function test_editing_the_discount_total_row_reallocates_and_retaxes(): void
    {
        $order = $this->orderWith([
            ['price' => 100, 'qty' => 1, 'rate' => 10],
            ['price' => 100, 'qty' => 1, 'rate' => 10],
        ], 0);
        AdminOrder::updateSubTotal($order->id);

        $row = ShopOrderTotal::where('order_id', $order->id)->where('code', 'discount')->first();
        AdminOrder::updateRowOrderTotal([
            'id' => $row->id,
            'code' => 'discount',
            'value' => 40,
            'text' => '-40',
        ]);

        $shares = $this->lineDiscounts(ShopOrder::find($order->id));
        $this->assertEqualsWithDelta(40.00, array_sum($shares), 0.001, 'the new discount reached the lines');

        $fresh = ShopOrder::find($order->id);
        // (100-20)*10% x 2 = 16.00
        $this->assertEqualsWithDelta(16.00, (float) $fresh->tax, 0.01, 'line taxes followed the new discount');
        $this->assertEqualsWithDelta(176.00, (float) $fresh->total, 0.01, '200 + 16 - 40');
    }

    /**
     * 5 — the discount may never exceed the pre-tax subtotal (F17): an order whose
     * total goes negative is not a discount, it is a data-entry accident.
     */
    public function test_discount_is_capped_at_the_pre_tax_subtotal(): void
    {
        $order = $this->orderWith([
            ['price' => 100, 'qty' => 1, 'rate' => 10],
        ], 250);

        $order->reallocateDiscountAndTax();
        AdminOrder::updateSubTotal($order->id);

        $fresh = ShopOrder::find($order->id);
        $this->assertEqualsWithDelta(100.00, (float) $fresh->discount, 0.01, 'capped at subtotal');
        $this->assertEqualsWithDelta(0.00, (float) $fresh->tax, 0.01, 'nothing left to tax');
        $this->assertEqualsWithDelta(0.00, (float) $fresh->total, 0.01, 'total never goes negative');
    }

    /**
     * 6 — a line with no rate contributes no tax, and still takes its share of the
     * discount. Legacy lines land here (tax_rate defaults to 0), so this asserts they
     * degrade quietly rather than throwing or inventing tax.
     */
    public function test_lines_without_a_rate_take_their_share_but_add_no_tax(): void
    {
        $order = $this->orderWith([
            ['price' => 100, 'qty' => 1, 'rate' => 0],
            ['price' => 100, 'qty' => 1, 'rate' => 10],
        ], 50);

        $order->reallocateDiscountAndTax();

        $shares = $this->lineDiscounts(ShopOrder::find($order->id));
        $taxes = $this->lineTaxes(ShopOrder::find($order->id));
        $this->assertEqualsWithDelta(25.00, $shares['L0'], 0.01);
        $this->assertEqualsWithDelta(0.00, $taxes['L0'], 0.01, 'no rate, no tax');
        $this->assertEqualsWithDelta(7.50, $taxes['L1'], 0.01, '(100-25) x 10%');
    }

    /**
     * 8 — a line written before `tax_rate` existed must not lose its tax the first time
     * anyone edits the order. Its rate reads 0 while its amount does not, and the naive
     * formula would multiply that 0 straight through — recalculation runs on ordinary
     * admin edits, so the damage would arrive quietly and be blamed on something else.
     */
    public function test_a_legacy_line_without_a_rate_keeps_its_tax(): void
    {
        $order = $this->makeOrder(['discount' => 0]);
        // Exactly the state the column upgrade leaves unreachable rows in: an amount,
        // no rate.
        $this->makeOrderItem($order, [
            'name' => 'L0',
            'qty' => 1,
            'price' => 200,
            'total_price' => 200,
            'tax' => 17.00,
            'tax_rate' => 0,
        ]);

        ShopOrder::find($order->id)->reallocateDiscountAndTax();

        $this->assertEqualsWithDelta(
            17.00,
            $this->lineTaxes(ShopOrder::find($order->id))['L0'],
            0.01,
            'the rate is recovered from the amount, not treated as no tax'
        );
    }

    /**
     * 7 — zero discount leaves every line at zero and taxes the full amount, i.e. the
     * change is inert for the ordinary order with no coupon.
     */
    public function test_an_order_without_a_discount_is_unaffected(): void
    {
        $order = $this->orderWith([
            ['price' => 100, 'qty' => 2, 'rate' => 10],
        ], 0);

        $order->reallocateDiscountAndTax();
        AdminOrder::updateSubTotal($order->id);

        $fresh = ShopOrder::find($order->id);
        $this->assertEqualsWithDelta(0.00, $this->lineDiscounts($fresh)['L0'], 0.01);
        $this->assertEqualsWithDelta(20.00, (float) $fresh->tax, 0.01);
        $this->assertEqualsWithDelta(220.00, (float) $fresh->total, 0.01);
    }
}
