<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Admin\Livewire\OrderManager;
use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopOrderHistory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopOrders;
use GP247\Shop\Tests\TestCase;

/**
 * E2E for the shop-admin order HEADER editing (US-SADM-order-info-edit):
 * saveOrderInfo() persists the customer/shipping fields, the order note and the
 * payment/shipping method, logs one history row per changed field (parity with
 * the legacy postOrderUpdate), never touches the read-only email, and is gated
 * by `admin_order`. Restores the edit capability lost in the group-E migration.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-order-info-edit
 * @aidlc-adr ADR-006, ADR-007
 */
class OrderInfoEditTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use MakesShopOrders;

    /**
     * Order fixture with the header fields saveOrderInfo requires non-empty.
     *
     * @param array<string, mixed> $overrides
     * @return ShopOrder
     */
    private function makeEditableOrder(array $overrides = []): ShopOrder
    {
        return $this->makeOrder(array_merge([
            'phone' => '0900000000',
            'address1' => '1 Old Street',
            'country' => 'VN',
        ], $overrides));
    }

    public function test_save_order_info_updates_header_fields(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $order = $this->makeEditableOrder();

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->set('form.first_name', 'New Name')
            ->set('form.phone', '0911111111')
            ->set('form.company', 'ZZ Corp')
            ->set('form.address1', '2 New Street')
            ->set('form.comment', 'Deliver after 6pm')
            ->call('saveOrderInfo')
            ->assertHasNoErrors();

        $fresh = ShopOrder::find($order->id);
        $this->assertSame('New Name', $fresh->first_name);
        $this->assertSame('0911111111', $fresh->phone);
        $this->assertSame('ZZ Corp', $fresh->company);
        $this->assertSame('2 New Street', $fresh->address1);
        $this->assertSame('Deliver after 6pm', $fresh->comment);
    }

    public function test_save_order_info_logs_history_per_changed_field(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $order = $this->makeEditableOrder();
        $before = ShopOrderHistory::where('order_id', $order->id)->count();

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->set('form.first_name', 'Changed Once')
            ->set('form.phone', '0922222222')
            ->call('saveOrderInfo')
            ->assertHasNoErrors();

        $this->assertSame($before + 2, ShopOrderHistory::where('order_id', $order->id)->count());
    }

    public function test_save_order_info_without_changes_logs_nothing(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $order = $this->makeEditableOrder();
        $before = ShopOrderHistory::where('order_id', $order->id)->count();

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->call('saveOrderInfo')
            ->assertHasNoErrors();

        $this->assertSame($before, ShopOrderHistory::where('order_id', $order->id)->count());
    }

    public function test_save_order_info_requires_first_name(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $order = $this->makeEditableOrder();

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->set('form.first_name', '')
            ->call('saveOrderInfo')
            ->assertHasErrors(['form.first_name']);

        $this->assertSame('ZZ Order', ShopOrder::find($order->id)->first_name);
    }

    public function test_save_order_info_never_changes_email(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $order = $this->makeEditableOrder();
        $originalEmail = $order->email;

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->set('form.email', 'hacked@example.com')
            ->set('form.first_name', 'New Name')
            ->call('saveOrderInfo')
            ->assertHasNoErrors();

        $this->assertSame($originalEmail, ShopOrder::find($order->id)->email);
    }

    public function test_save_order_info_updates_payment_and_shipping_method(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $order = $this->makeEditableOrder([
            'payment_method' => 'Cash',
            'shipping_method' => 'ShippingStandard',
        ]);

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->set('form.payment_method', 'PaypalExpress')
            ->set('form.shipping_method', 'ShippingFast')
            ->call('saveOrderInfo')
            ->assertHasNoErrors();

        $fresh = ShopOrder::find($order->id);
        $this->assertSame('PaypalExpress', $fresh->payment_method);
        $this->assertSame('ShippingFast', $fresh->shipping_method);
    }

    public function test_save_order_info_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);
        $order = $this->makeEditableOrder();

        $component = Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->set('form.first_name', 'Blocked');

        $this->assertDenied(fn () => $component->call('saveOrderInfo'));
        $this->assertSame('ZZ Order', ShopOrder::find($order->id)->first_name);
    }
}
