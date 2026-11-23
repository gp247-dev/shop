<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Admin\Livewire\OrderManager;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopOrders;
use GP247\Shop\Tests\TestCase;

/**
 * E2E for the shop-admin order DETAIL screen (group E, US-SADM-003): the edit/{id}
 * route loads one store-scoped order with its line items, total breakdown and
 * status history into component state (parity with AdminOrder::getOrderAdmin).
 * Domain unchanged. Gated by `admin_order`.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-003
 * @aidlc-adr ADR-001, ADR-006, ADR-007
 */
class OrderDetailTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use MakesShopOrders;

    public function test_detail_loads_order_into_state(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $order = $this->makeOrder(['email' => 'zz-detail@example.com']);
        $this->makeOrderItem($order);

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->assertSet('editingId', (string) $order->id)
            ->assertSet('form.email', 'zz-detail@example.com')
            ->assertSet('form.status', 1);
    }

    public function test_detail_loads_items_totals_history(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $order = $this->makeOrder();
        $this->makeOrderItem($order);

        $component = Livewire::test(OrderManager::class, ['id' => (string) $order->id]);

        $this->assertCount(1, $component->get('items'));
        $this->assertNotEmpty($component->get('totals'));
    }

    public function test_detail_does_not_load_order_from_other_store(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $other = $this->makeOrder(['store_id' => 'OTHER-STORE-ID']);

        Livewire::test(OrderManager::class, ['id' => (string) $other->id])
            ->assertSet('editingId', null);
    }

    public function test_detail_denied_without_permission(): void
    {
        $this->asUser();
        $order = $this->makeOrder();

        $this->assertDenied(fn () => Livewire::test(OrderManager::class, ['id' => (string) $order->id]));
    }
}
