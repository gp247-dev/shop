<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Admin\Models\AdminOrder;
use GP247\Shop\Models\ShopOrderStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopOrders;
use GP247\Shop\Tests\TestCase;

/**
 * Dashboard "order value placed" is measured correctly (T3).
 *
 * The old SUM(total/exchange_rate) counted cancelled/failed orders and dropped any order
 * with a NULL exchange_rate (total/NULL = NULL). Fixed: exclude Canceled/Failed and
 * COALESCE(NULLIF(exchange_rate,0),1) so base/legacy orders are not lost.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-revenue-semantics
 * @aidlc-adr shop-admin_revenue-semantics
 */
class RevenueSemanticsTest extends TestCase
{
    use DatabaseTransactions;
    use MakesShopOrders;

    /**
     * Sum this month's dashboard revenue (all stores).
     *
     * @return float
     */
    private function dashboardMonthRevenue(): float
    {
        return (float) collect(AdminOrder::getSumOrderTotalInMonth())->sum('total_amount');
    }

    /**
     * Cancelled and failed orders do not count toward placed revenue.
     */
    public function test_cancelled_and_failed_orders_excluded(): void
    {
        $base = $this->dashboardMonthRevenue();

        $this->makeOrder(['status' => ShopOrderStatus::CANCELED, 'total' => 500, 'exchange_rate' => 1]);
        $this->makeOrder(['status' => ShopOrderStatus::FAILED, 'total' => 700, 'exchange_rate' => 1]);

        $this->assertEqualsWithDelta($base, $this->dashboardMonthRevenue(), 0.01, 'cancelled/failed must not add to revenue');
    }

    /**
     * A completed order counts.
     */
    public function test_completed_order_counts(): void
    {
        $base = $this->dashboardMonthRevenue();

        $this->makeOrder(['status' => ShopOrderStatus::DONE, 'total' => 300, 'exchange_rate' => 1]);

        $this->assertEqualsWithDelta($base + 300, $this->dashboardMonthRevenue(), 0.01);
    }

    /**
     * An order with a NULL exchange_rate is NOT dropped (the old total/NULL bug); it is
     * treated as base (rate 1).
     */
    public function test_null_exchange_rate_order_is_not_dropped(): void
    {
        $base = $this->dashboardMonthRevenue();

        $order = $this->makeOrder(['status' => ShopOrderStatus::PROCESSING, 'total' => 250]);
        // Force a NULL rate as legacy data would have.
        \GP247\Shop\Models\ShopOrder::where('id', $order->id)->update(['exchange_rate' => null]);

        $this->assertEqualsWithDelta($base + 250, $this->dashboardMonthRevenue(), 0.01, 'NULL-rate order counts as base');
    }

    /**
     * A multi-currency order is converted to base via its rate (rate 2 → half).
     */
    public function test_rate_is_applied_for_base_conversion(): void
    {
        $base = $this->dashboardMonthRevenue();

        $this->makeOrder(['status' => ShopOrderStatus::DONE, 'total' => 200, 'exchange_rate' => 2]);

        $this->assertEqualsWithDelta($base + 100, $this->dashboardMonthRevenue(), 0.01, '200 at rate 2 is 100 in base');
    }
}
