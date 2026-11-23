<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Core\Models\AdminLanguage;
use GP247\Core\Models\AdminRole;
use GP247\Core\Models\AdminUser;
use GP247\Shop\Admin\Models\AdminOrder;
use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopOrderHistory;
use GP247\Shop\Models\ShopOrderTransaction;
use GP247\Shop\Models\ShopPaymentStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopOrders;
use GP247\Shop\Tests\TestCase;

/**
 * The order payment ledger.
 *
 * What the ledger replaces is a single `received` column, and the invariants below are
 * the ones that column could not hold: money arriving twice from a replayed gateway
 * callback, a refund of part of an order, and a payment whose date differs from the
 * order date. `received` survives as a cache, so the test that matters most is that it
 * always equals Σ payment − Σ refund — a cache that silently drifts is the one real risk
 * of keeping it (RISK-TECH-received-derivation-drift).
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-order-payment-ledger
 * @aidlc-adr shop_order-payment-ledger
 */
class OrderPaymentLedgerTest extends TestCase
{
    use DatabaseTransactions;
    use MakesShopOrders;

    /**
     * An order of 100 with its totals already synced.
     *
     * @return ShopOrder
     */
    private function orderOf100(): ShopOrder
    {
        $order = $this->makeOrder();
        $this->makeOrderItem($order, ['qty' => 2, 'price' => 50, 'total_price' => 100, 'tax' => 0]);
        AdminOrder::updateSubTotal($order->id);

        return ShopOrder::find($order->id);
    }

    /**
     * L2/L3/L5: one payment moves received, balance and status together.
     */
    public function test_a_payment_drives_received_balance_and_status(): void
    {
        $order = $this->orderOf100();

        $order->recordPayment(40, 'CashPayment');

        $fresh = ShopOrder::find($order->id);
        $this->assertEqualsWithDelta(40, (float) $fresh->received, 0.01);
        $this->assertEqualsWithDelta(60, (float) $fresh->balance, 0.01);
        $this->assertSame(ShopPaymentStatus::PARTIAL, (int) $fresh->payment_status);
        $this->assertSame(1, ShopOrderTransaction::where('order_id', $order->id)->count());
    }

    /**
     * Several payments accumulate — the case a single column could express only by
     * being overwritten, losing every earlier date.
     */
    public function test_payments_accumulate_and_settle_the_order(): void
    {
        $order = $this->orderOf100();

        $order->recordPayment(30, 'CashPayment', null, '2026-08-01 09:00:00');
        $order->recordPayment(70, 'CashPayment', null, '2026-08-20 15:30:00');

        $fresh = ShopOrder::find($order->id);
        $this->assertEqualsWithDelta(100, (float) $fresh->received, 0.01);
        $this->assertEqualsWithDelta(0, (float) $fresh->balance, 0.01);
        $this->assertSame(ShopPaymentStatus::PAID, (int) $fresh->payment_status);

        $dates = ShopOrderTransaction::where('order_id', $order->id)
            ->orderBy('paid_at')->pluck('paid_at')->map(fn ($d) => $d->format('Y-m-d'))->all();
        $this->assertSame(['2026-08-01', '2026-08-20'], $dates, 'each payment keeps its own date');
    }

    /**
     * F13: refunding part of an order reduces what was received; it does not declare the
     * whole order refunded while money is still held.
     */
    public function test_a_partial_refund_leaves_the_order_partly_paid(): void
    {
        $order = $this->orderOf100();
        $order->recordPayment(100, 'PaypalExpress', 'CAP-1');

        $order->recordRefund(30, 'PaypalExpress', 'REF-1');

        $fresh = ShopOrder::find($order->id);
        $this->assertEqualsWithDelta(70, (float) $fresh->received, 0.01);
        $this->assertEqualsWithDelta(30, (float) $fresh->balance, 0.01);
        $this->assertSame(ShopPaymentStatus::PARTIAL, (int) $fresh->payment_status);
    }

    /**
     * NFR-SEC-payment-idempotency: a replayed gateway callback must not take the money
     * twice. This is the guarantee the platform now makes for every plugin, rather than
     * each one inventing its own.
     */
    public function test_replaying_a_gateway_reference_records_the_money_once(): void
    {
        $order = $this->orderOf100();

        $order->recordPayment(100, 'PaypalExpress', 'CAPTURE-XYZ');
        $order->recordPayment(100, 'PaypalExpress', 'CAPTURE-XYZ');
        $order->recordPayment(100, 'PaypalExpress', 'CAPTURE-XYZ');

        $this->assertSame(1, ShopOrderTransaction::where('order_id', $order->id)->count());
        $this->assertEqualsWithDelta(100, (float) ShopOrder::find($order->id)->received, 0.01);
    }

    /**
     * L1: amounts are magnitudes. A zero or negative movement is not money changing
     * hands and must not enter the ledger — a refund is its own type, not a negative
     * payment.
     */
    public function test_non_positive_movements_are_refused(): void
    {
        $order = $this->orderOf100();

        $this->assertNull($order->recordPayment(0));
        $this->assertNull($order->recordPayment(-50));
        $this->assertNull($order->recordRefund(-10));
        $this->assertSame(0, ShopOrderTransaction::where('order_id', $order->id)->count());
    }

    /**
     * D5: the base-currency figure is fixed when the movement is written, so aggregate
     * reports never re-convert — which is what silently mixes units after a rebase.
     */
    public function test_amount_base_is_snapshotted_from_the_order_rate(): void
    {
        $order = $this->orderOf100();
        $order->exchange_rate = 2.0;
        $order->save();

        $order->recordPayment(50, 'CashPayment');

        $row = ShopOrderTransaction::where('order_id', $order->id)->first();
        $this->assertEqualsWithDelta(50, (float) $row->amount, 0.01);
        $this->assertEqualsWithDelta(25, (float) $row->amount_base, 0.01, '50 at rate 2 is 25 in base');
    }

    /**
     * The deprecated shortcut still works for plugins that have not moved yet, and it
     * collects only what is actually outstanding rather than blindly writing the total.
     */
    public function test_deprecated_process_payment_paid_settles_the_outstanding_amount(): void
    {
        $order = $this->orderOf100();
        $order->recordPayment(30, 'CashPayment');

        ShopOrder::find($order->id)->processPaymentPaid();

        $fresh = ShopOrder::find($order->id);
        $this->assertEqualsWithDelta(100, (float) $fresh->received, 0.01);
        $this->assertEqualsWithDelta(0, (float) $fresh->balance, 0.01);
        $this->assertSame(2, ShopOrderTransaction::where('order_id', $order->id)->count());
    }

    /**
     * RISK-TECH-received-derivation-drift: `received` is a cache, so recalculating an
     * order must rebuild it from the ledger. A value written directly behind the seam's
     * back is corrected, not carried forward.
     */
    public function test_recalculation_rebuilds_received_from_the_ledger(): void
    {
        $order = $this->orderOf100();
        $order->recordPayment(60, 'CashPayment');

        // Simulate a stray writer bypassing the seam.
        ShopOrder::where('id', $order->id)->update(['received' => 999]);
        AdminOrder::updateSubTotal($order->id);

        $fresh = ShopOrder::find($order->id);
        $this->assertEqualsWithDelta(60, (float) $fresh->received, 0.01);
        $this->assertEqualsWithDelta(40, (float) $fresh->balance, 0.01);
    }

    // -------------------------------------------------------------------------
    // The 4th write path: admin order creation. postCreate
    // used to write received/balance/payment_status directly — the next recalc
    // read the (empty) ledger and silently zeroed the money
    // (RISK-BIZ-admin-create-received-drift).
    // -------------------------------------------------------------------------

    /**
     * A real administrator session for the postCreate HTTP path.
     *
     * @return AdminUser
     */
    private function loginAdministrator(): AdminUser
    {
        $user = AdminUser::create([
            'id' => 'AU-LGR-' . substr(uniqid(), -6),
            'username' => 'ledger_admin_' . substr(uniqid(), -6),
            'password' => bcrypt('secret'),
            'name' => 'Ledger Admin',
            'email' => 'ledger' . substr(uniqid(), -6) . '@test.local',
            'status' => 1,
        ]);
        $role = AdminRole::firstOrCreate(['slug' => 'administrator'], ['name' => 'Administrator']);
        $user->roles()->syncWithoutDetaching([$role->id]);

        $this->actingAs($user, 'admin');
        session(['adminStoreId' => $this->storeId()]);

        return $user;
    }

    /**
     * Create an order through the real admin create endpoint.
     *
     * @param float $received Money typed into the "received" box.
     * @return ShopOrder
     */
    private function createOrderThroughAdminScreen(float $received): ShopOrder
    {
        $product = $this->makeProduct(['price' => 50, 'stock' => 100, 'tax_id' => 0]);
        // WHY: AdminProduct::getDetail left-joins the description table on the
        // active locale — without a row per language, postCreate silently skips
        // the line (same fixture rule as OrderLinePriceIntegrityTest).
        foreach (array_keys(AdminLanguage::getListActive()->all()) as $code) {
            \GP247\Shop\Models\ShopProductDescription::insert([
                'product_id' => $product->id,
                'lang' => $code,
                'name' => 'ZZ Ledger Item',
                'keyword' => '',
                'description' => '',
                'content' => 'Content',
            ]);
        }
        $email = 'zz-ledger-create-' . uniqid() . '@example.com';

        $this->post(route('admin_order.post_create'), [
            'first_name' => 'ZZ LedgerCreate',
            'last_name' => 'Buyer',
            'email' => $email,
            'phone' => '0123456789',
            'address1' => 'Addr 1',
            'country' => 'US',
            'postcode' => '12345',
            'company' => 'ACME',
            'currency' => 'USD',
            'exchange_rate' => '1',
            'status' => '1',
            'payment_method' => 'Cash',
            'shipping_method' => 'Free',
            'shipping' => 0,
            'discount' => 0,
            'received' => $received,
            'products' => [
                ['product_id' => (string) $product->id, 'name' => 'ZZ line', 'qty' => 2, 'price' => 50, 'tax' => 0],
            ],
        ])->assertSessionHasNoErrors();

        $order = ShopOrder::where('email', $email)->first();
        $this->assertNotNull($order, 'postCreate must create the order');

        return $order;
    }

    /**
     * The money typed at creation is a ledger movement, and the derived trio
     * (received/balance/payment_status) agrees with it.
     */
    public function test_admin_create_with_received_writes_a_ledger_movement(): void
    {
        $this->loginAdministrator();

        $order = $this->createOrderThroughAdminScreen(40);

        $movements = ShopOrderTransaction::where('order_id', $order->id)->get();
        $this->assertCount(1, $movements);
        $this->assertSame(ShopOrderTransaction::TYPE_PAYMENT, $movements[0]->type);
        $this->assertEqualsWithDelta(40, (float) $movements[0]->amount, 0.01);

        $this->assertEqualsWithDelta(100, (float) $order->total, 0.01);
        $this->assertEqualsWithDelta(40, (float) $order->received, 0.01);
        $this->assertEqualsWithDelta(60, (float) $order->balance, 0.01);
        $this->assertSame(ShopPaymentStatus::PARTIAL, (int) $order->payment_status);
    }

    /**
     * The regression this path was patched for: recalculating the freshly created
     * order must NOT zero the money out, because it is now in the ledger.
     */
    public function test_admin_created_received_survives_the_next_recalculation(): void
    {
        $this->loginAdministrator();
        $order = $this->createOrderThroughAdminScreen(40);

        AdminOrder::updateSubTotal($order->id);

        $fresh = ShopOrder::find($order->id);
        $this->assertEqualsWithDelta(40, (float) $fresh->received, 0.01, 'received must survive a recalc');
        $this->assertEqualsWithDelta(60, (float) $fresh->balance, 0.01);
        $this->assertSame(ShopPaymentStatus::PARTIAL, (int) $fresh->payment_status);
    }

    /**
     * Creating with nothing collected stays clean: no ledger row, unpaid, balance
     * equal to the total — all derived, none written directly.
     */
    public function test_admin_create_without_received_leaves_no_ledger_row(): void
    {
        $this->loginAdministrator();

        $order = $this->createOrderThroughAdminScreen(0);

        $this->assertSame(0, ShopOrderTransaction::where('order_id', $order->id)->count());
        $this->assertEqualsWithDelta(0, (float) $order->received, 0.01);
        $this->assertEqualsWithDelta(100, (float) $order->balance, 0.01);
        $this->assertSame(ShopPaymentStatus::UNPAID, (int) $order->payment_status);
    }

    /**
     * Parity with the storefront: an admin-created order carries a "New order"
     * history row naming who created it (postCreate wrote none before).
     */
    public function test_admin_create_writes_a_new_order_history_row(): void
    {
        $admin = $this->loginAdministrator();

        $order = $this->createOrderThroughAdminScreen(0);

        $history = ShopOrderHistory::where('order_id', $order->id)->where('content', 'New order')->first();
        $this->assertNotNull($history, 'admin order creation must leave an audit row');
        $this->assertSame((string) $admin->id, (string) $history->admin_id);
    }
}
