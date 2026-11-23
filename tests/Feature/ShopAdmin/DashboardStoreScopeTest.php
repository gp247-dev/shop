<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Core\Models\AdminStore;
use GP247\Shop\Admin\Models\AdminCustomer;
use GP247\Shop\Admin\Models\AdminOrder;
use GP247\Shop\Admin\Models\AdminProduct;
use GP247\Shop\Models\ShopCustomer;
use GP247\Shop\Models\ShopOrderStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopOrders;
use GP247\Shop\Tests\TestCase;

/**
 * The shop dashboard blocks only count the store the admin session is scoped to: a store-admin bound to store B must not see
 * store A's orders, customers, products or revenue on the admin home, while the
 * ROOT admin keeps the all-stores totals it saw before.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-dashboard-store-scope
 * @aidlc-adr shop-admin_revenue-semantics, multi-store_store-admin-identity-binding
 */
class DashboardStoreScopeTest extends TestCase
{
    use DatabaseTransactions;
    use MakesShopOrders;

    /** @var string */
    private string $storeA;

    /** @var string */
    private string $storeB;

    /** @var string */
    private string $marker;

    /**
     * Two fresh stores; store A holds one order, customer and product carrying a
     * unique marker so a leak into store B's dashboard is detectable.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(AdminOrder::class)) {
            $this->markTestSkipped('gp247/shop package is not installed in this app.');
        }

        $this->marker = 'ZZDash' . substr(uniqid(), -6);
        $this->storeA = (string) $this->makeStore()->id;
        $this->storeB = (string) $this->makeStore()->id;

        $this->makeOrder([
            'store_id' => $this->storeA,
            'first_name' => $this->marker . 'OrderA',
            'status' => ShopOrderStatus::DONE,
            'total' => 987654,
        ]);
        $this->makeCustomer($this->storeA, $this->marker . 'CustA');
        $this->makeProduct(['store_id' => $this->storeA]);
    }

    public function test_a_store_admin_sees_only_their_own_store_counts(): void
    {
        $this->makeOrder(['store_id' => $this->storeB, 'first_name' => 'ZZB1']);
        $this->makeOrder(['store_id' => $this->storeB, 'first_name' => 'ZZB2']);
        $this->makeProduct(['store_id' => $this->storeB]);
        $this->makeCustomer($this->storeB, 'ZZCustB');

        $this->assertSame(2, AdminOrder::getTotalOrder($this->storeB));
        $this->assertSame(1, AdminProduct::getTotalProduct($this->storeB));
        $this->assertSame(1, AdminCustomer::getTotalCustomer($this->storeB));
    }

    public function test_the_root_admin_still_counts_every_store(): void
    {
        $root = defined('GP247_STORE_ID_ROOT') ? GP247_STORE_ID_ROOT : '1';

        $this->assertSame(AdminOrder::count(), AdminOrder::getTotalOrder($root));
        $this->assertSame(AdminOrder::count(), AdminOrder::getTotalOrder());
        $this->assertSame(AdminProduct::count(), AdminProduct::getTotalProduct($root));
        $this->assertSame(AdminCustomer::count(), AdminCustomer::getTotalCustomer($root));
    }

    public function test_the_kpi_block_shows_the_session_store_totals(): void
    {
        $this->makeOrder(['store_id' => $this->storeB]);
        $this->makeOrder(['store_id' => $this->storeB]);
        $this->makeOrder(['store_id' => $this->storeB]);
        $this->makeProduct(['store_id' => $this->storeB]);
        $this->makeProduct(['store_id' => $this->storeB]);
        $this->makeCustomer($this->storeB, 'ZZCustKpi');
        session(['adminStoreId' => $this->storeB]);

        $html = (string) $this->view('gp247-shop-admin::component.top_info');
        preg_match_all('#text-2xl font-bold[^>]*>\s*([^<]+?)\s*</p>#', $html, $values);

        // Stat order is fixed by the block: orders, products, customers.
        $this->assertSame(['3', '2', '1'], $values[1]);
    }

    public function test_latest_orders_block_hides_other_store_orders(): void
    {
        $this->makeOrder(['store_id' => $this->storeB, 'first_name' => $this->marker . 'OrderB']);
        session(['adminStoreId' => $this->storeB]);

        $this->view('gp247-shop-admin::component.new_order')
            ->assertSee($this->marker . 'OrderB')
            ->assertDontSee($this->marker . 'OrderA');
    }

    public function test_latest_customers_block_hides_other_store_customers(): void
    {
        $this->makeCustomer($this->storeB, $this->marker . 'CustB');
        session(['adminStoreId' => $this->storeB]);

        $this->view('gp247-shop-admin::component.new_customer')
            ->assertSee($this->marker . 'CustB')
            ->assertDontSee($this->marker . 'CustA');
    }

    public function test_revenue_charts_exclude_other_store_revenue(): void
    {
        session(['adminStoreId' => $this->storeB]);

        $this->assertSame([], array_filter($this->chartValues('gp247-shop-admin::component.order_year')));
        $this->assertSame([], array_filter($this->chartValues('gp247-shop-admin::component.order_month')));
    }

    public function test_the_root_admin_revenue_chart_includes_every_store(): void
    {
        session(['adminStoreId' => defined('GP247_STORE_ID_ROOT') ? GP247_STORE_ID_ROOT : '1']);

        $allStores = (float) collect(AdminOrder::getSumOrderTotalInYear())->pluck('total_amount', 'ym')->get(date('Y-m'), 0);
        $values = $this->chartValues('gp247-shop-admin::component.order_year');

        $this->assertGreaterThanOrEqual(987654, $allStores);
        $this->assertEqualsWithDelta($allStores, (float) end($values), 0.01, 'current month bar = every store');
    }

    /**
     * Read the data points a dashboard chart block hands to the ApexCharts partial.
     *
     * @param string $view
     * @return array<int, float>
     */
    private function chartValues(string $view): array
    {
        $html = html_entity_decode((string) $this->view($view), ENT_QUOTES);
        $this->assertSame(1, preg_match('#gp247DashboardChart\((\{.*?\})\)#s', $html, $match), 'chart config rendered');

        return array_map('floatval', json_decode($match[1], true)['values']);
    }

    /**
     * @return AdminStore
     */
    private function makeStore(): AdminStore
    {
        $suffix = substr(uniqid(), -8);

        return AdminStore::create([
            'language' => 'en',
            'currency' => 'USD',
            'template' => 'GP247Front',
            'code' => 'ds' . $suffix,
            'domain' => 'ds-' . $suffix . '.local',
            'status' => 1,
        ]);
    }

    /**
     * @param string $storeId
     * @param string $firstName
     * @return ShopCustomer
     */
    private function makeCustomer(string $storeId, string $firstName): ShopCustomer
    {
        return ShopCustomer::create([
            'first_name' => $firstName,
            'last_name' => 'Dash',
            'email' => 'zz-dash-' . uniqid() . '@example.com',
            'password' => bcrypt('secret-' . uniqid()),
            'status' => 1,
            'store_id' => $storeId,
        ]);
    }
}
