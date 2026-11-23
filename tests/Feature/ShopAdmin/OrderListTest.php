<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Admin\Livewire\OrderManager;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopOrders;
use GP247\Shop\Tests\TestCase;

/**
 * E2E for the shop-admin order LIST screen (group E, US-SADM-003): store-scoped
 * listing on the core ResourcePanel base with keyword + order-status + date-range
 * filters (parity with AdminOrder::getOrderListAdmin). Domain unchanged.
 * Gated by `admin_order`.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-003
 * @aidlc-adr ADR-001, ADR-006, ADR-007
 */
class OrderListTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use MakesShopOrders;

    public function test_lists_orders_in_store(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $order = $this->makeOrder(['email' => 'zz-list-find@example.com']);

        Livewire::test(OrderManager::class)->assertSee($order->email);
    }

    public function test_keyword_filters_by_email(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $keep = $this->makeOrder(['email' => 'zz-keyword-keep@example.com']);
        $drop = $this->makeOrder(['email' => 'zz-keyword-drop@example.com']);

        Livewire::test(OrderManager::class)
            ->set('keyword', 'keyword-keep')
            ->assertSee($keep->email)
            ->assertDontSee($drop->email);
    }

    public function test_filters_by_order_status(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $new = $this->makeOrder(['status' => 1, 'email' => 'zz-status-new@example.com']);
        $done = $this->makeOrder(['status' => 5, 'email' => 'zz-status-done@example.com']);

        Livewire::test(OrderManager::class)
            ->set('filterStatus', '5')
            ->assertSee($done->email)
            ->assertDontSee($new->email);
    }

    public function test_filters_by_date_range(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $old = $this->makeOrder(['email' => 'zz-date-old@example.com']);
        $old->created_at = '2000-01-01 00:00:00';
        $old->save();
        $recent = $this->makeOrder(['email' => 'zz-date-recent@example.com']);

        Livewire::test(OrderManager::class)
            ->set('filterFrom', '2020-01-01')
            ->assertSee($recent->email)
            ->assertDontSee($old->email);
    }

    public function test_list_denied_without_permission(): void
    {
        $this->asUser();

        $this->assertDenied(fn () => Livewire::test(OrderManager::class));
    }
}
