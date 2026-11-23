<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Admin\Livewire\OrderManager;
use GP247\Shop\Admin\Models\AdminOrder;
use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopOrderHistory;
use GP247\Shop\Models\ShopOrderTotal;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopOrders;
use GP247\Shop\Tests\TestCase;

/**
 * E2E for the shop-admin order TOTALS editing (US-SADM-order-info-edit):
 * updateTotalRow() edits the shipping/discount/other_fee breakdown rows via
 * AdminOrder::updateRowOrderTotal (re-summing total/balance), rejects rows
 * outside the editing order (the legacy IDOR gap) and rows whose code is derived
 * (subtotal/tax/total), logs history and is gated by `admin_order`.
 *
 * Every stored amount is a NON-NEGATIVE
 * magnitude and the sign lives in the formula (ShopOrderTotal::SIGN_MAP), so a
 * discount is entered as 20 and subtracts; negative input is refused. `received`
 * is no longer a total row at all — it is a payment edited through
 * updateReceived() (ADR shop-admin_money-sign-convention D1/D3).
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-order-info-edit, US-SADM-money-sign-convention
 * @aidlc-adr ADR-006, ADR-007, shop-admin_money-sign-convention
 */
class OrderTotalsEditTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use MakesShopOrders;

    /**
     * Add one editable breakdown row (shipping/discount/other_fee).
     *
     * @param ShopOrder $order
     * @param string    $code
     * @param float     $value
     * @return ShopOrderTotal
     */
    private function makeTotalRow(ShopOrder $order, string $code, float $value = 0): ShopOrderTotal
    {
        $sorts = ['shipping' => 10, 'discount' => 20, 'other_fee' => 30];

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
     * Order with one 100-value line and synced breakdown rows (subtotal 100).
     *
     * @return array{0: ShopOrder, 1: array<string, ShopOrderTotal>}
     */
    private function makeOrderWithTotals(): array
    {
        $order = $this->makeOrder();
        $this->makeOrderItem($order, ['qty' => 2, 'price' => 50, 'total_price' => 100]);
        $rows = [];
        foreach (['shipping', 'discount', 'other_fee'] as $code) {
            $rows[$code] = $this->makeTotalRow($order, $code);
        }
        AdminOrder::updateSubTotal($order->id);

        return [$order->fresh(), $rows];
    }

    public function test_update_shipping_resums_total_and_balance(): void
    {
        $this->asUser(slugs: ['admin_order']);
        [$order, $rows] = $this->makeOrderWithTotals();

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->call('updateTotalRow', (string) $rows['shipping']->id, 10)
            ->assertHasNoErrors();

        $fresh = ShopOrder::find($order->id);
        $this->assertEqualsWithDelta(10, (float) $fresh->shipping, 0.01);
        $this->assertEqualsWithDelta(110, (float) $fresh->total, 0.01);
        $this->assertEqualsWithDelta(110, (float) $fresh->balance, 0.01);
        $this->assertEqualsWithDelta(10, (float) ShopOrderTotal::find($rows['shipping']->id)->value, 0.01);
    }

    /**
     * A discount is entered as a POSITIVE magnitude and subtracts from the total —
     * the sign comes from SIGN_MAP, not from the stored value (D1/D2).
     */
    public function test_update_discount_stores_magnitude_and_subtracts(): void
    {
        $this->asUser(slugs: ['admin_order']);
        [$order, $rows] = $this->makeOrderWithTotals();

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->call('updateTotalRow', (string) $rows['discount']->id, 20)
            ->assertHasNoErrors();

        $fresh = ShopOrder::find($order->id);
        $this->assertEqualsWithDelta(20, (float) $fresh->discount, 0.01);
        $this->assertEqualsWithDelta(20, (float) ShopOrderTotal::find($rows['discount']->id)->value, 0.01);
        $this->assertEqualsWithDelta(80, (float) $fresh->total, 0.01);
        $this->assertEqualsWithDelta(80, (float) $fresh->balance, 0.01);
    }

    /**
     * Negative input is refused rather than reinterpreted: under the old convention
     * "-20" was the documented way to enter a discount, so silently accepting it
     * would now double back on the admin's intent (D1).
     */
    public function test_update_total_row_rejects_negative_value(): void
    {
        $this->asUser(slugs: ['admin_order']);
        [$order, $rows] = $this->makeOrderWithTotals();

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->call('updateTotalRow', (string) $rows['discount']->id, -20);

        $this->assertEqualsWithDelta(0, (float) ShopOrderTotal::find($rows['discount']->id)->value, 0.01);
        $this->assertEqualsWithDelta(100, (float) ShopOrder::find($order->id)->total, 0.01);
    }

    /**
     * `received` is a payment, not a total row: it is recorded through recordPayment()
     * — one ledger row per movement — and only drives the balance; total is untouched
     * (ADR shop_order-payment-ledger).
     */
    public function test_update_received_drives_balance(): void
    {
        $this->asUser(slugs: ['admin_order']);
        [$order] = $this->makeOrderWithTotals();

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->call('recordPayment', 100)
            ->assertHasNoErrors();

        $fresh = ShopOrder::find($order->id);
        $this->assertEqualsWithDelta(100, (float) $fresh->received, 0.01);
        $this->assertEqualsWithDelta(100, (float) $fresh->total, 0.01);
        $this->assertEqualsWithDelta(0, (float) $fresh->balance, 0.01);
        $this->assertSame(0, ShopOrderTotal::where('order_id', $order->id)->where('code', 'received')->count());
    }

    public function test_update_received_rejects_negative_value(): void
    {
        $this->asUser(slugs: ['admin_order']);
        [$order] = $this->makeOrderWithTotals();

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->call('recordPayment', -50);

        $this->assertEqualsWithDelta(0, (float) ShopOrder::find($order->id)->received, 0.01);
    }

    public function test_update_received_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);
        [$order] = $this->makeOrderWithTotals();

        $component = Livewire::test(OrderManager::class, ['id' => (string) $order->id]);

        $this->assertDenied(fn () => $component->call('recordPayment', 50));
        $this->assertEqualsWithDelta(0, (float) ShopOrder::find($order->id)->received, 0.01);
    }

    public function test_update_other_fee_logs_history(): void
    {
        $this->asUser(slugs: ['admin_order']);
        [$order, $rows] = $this->makeOrderWithTotals();
        $before = ShopOrderHistory::where('order_id', $order->id)->count();

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->call('updateTotalRow', (string) $rows['other_fee']->id, 5)
            ->assertHasNoErrors();

        $this->assertSame($before + 1, ShopOrderHistory::where('order_id', $order->id)->count());
        $this->assertEqualsWithDelta(5, (float) ShopOrder::find($order->id)->other_fee, 0.01);
    }

    /**
     * A breakdown row belonging to ANOTHER order must be rejected — the legacy
     * postOrderUpdate accepted any pk (IDOR); the Livewire action scopes the row
     * to the order being edited.
     */
    public function test_update_rejects_row_of_another_order(): void
    {
        $this->asUser(slugs: ['admin_order']);
        [$order] = $this->makeOrderWithTotals();
        $other = $this->makeOrder();
        $foreignRow = $this->makeTotalRow($other, 'shipping');

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->call('updateTotalRow', (string) $foreignRow->id, 999);

        $this->assertEqualsWithDelta(0, (float) ShopOrderTotal::find($foreignRow->id)->value, 0.01);
        $this->assertEqualsWithDelta(0, (float) ShopOrder::find($other->id)->shipping, 0.01);
    }

    /**
     * Derived rows (subtotal/tax/total) are recomputed from line items — never
     * directly editable through updateTotalRow.
     */
    public function test_update_rejects_non_editable_code(): void
    {
        $this->asUser(slugs: ['admin_order']);
        [$order] = $this->makeOrderWithTotals();
        $subtotalRow = ShopOrderTotal::where('order_id', $order->id)->where('code', 'subtotal')->first();

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->call('updateTotalRow', (string) $subtotalRow->id, 999);

        $this->assertEqualsWithDelta(100, (float) ShopOrderTotal::find($subtotalRow->id)->value, 0.01);
    }

    public function test_update_rejects_non_numeric_value(): void
    {
        $this->asUser(slugs: ['admin_order']);
        [$order, $rows] = $this->makeOrderWithTotals();

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->call('updateTotalRow', (string) $rows['shipping']->id, 'abc');

        $this->assertEqualsWithDelta(0, (float) ShopOrderTotal::find($rows['shipping']->id)->value, 0.01);
        $this->assertEqualsWithDelta(100, (float) ShopOrder::find($order->id)->total, 0.01);
    }

    public function test_update_total_row_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);
        [$order, $rows] = $this->makeOrderWithTotals();

        $component = Livewire::test(OrderManager::class, ['id' => (string) $order->id]);

        $this->assertDenied(fn () => $component->call('updateTotalRow', (string) $rows['shipping']->id, 10));
        $this->assertEqualsWithDelta(0, (float) ShopOrderTotal::find($rows['shipping']->id)->value, 0.01);
    }
}
