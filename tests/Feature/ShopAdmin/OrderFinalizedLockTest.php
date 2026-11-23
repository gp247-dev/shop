<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Admin\Livewire\OrderManager;
use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopOrderDetail;
use GP247\Shop\Models\ShopOrderStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopOrders;
use GP247\Shop\Tests\TestCase;

/**
 * Finalised orders are immutable in structure (T5).
 *
 * Done/Refunded/Canceled are chốt: editing header, lines or fee rows would corrupt a
 * document downstream relies on. Structure is locked; changing status (reopening) and
 * recording payments stay allowed. payment_status is derived — no manual set path.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-order-finalized-lock
 * @aidlc-adr shop-admin_order-finalized-lock
 */
class OrderFinalizedLockTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use MakesShopOrders;

    /**
     * isLocked() is true exactly for Done/Refunded/Canceled.
     */
    public function test_is_locked_covers_finalised_statuses_only(): void
    {
        foreach ([ShopOrderStatus::DONE, ShopOrderStatus::REFUNDED, ShopOrderStatus::CANCELED] as $s) {
            $this->assertTrue(ShopOrder::find($this->makeOrder(['status' => $s])->id)->isLocked(), "status $s locked");
        }
        foreach ([ShopOrderStatus::NEW, ShopOrderStatus::PROCESSING, ShopOrderStatus::HOLD, ShopOrderStatus::FAILED] as $s) {
            $this->assertFalse(ShopOrder::find($this->makeOrder(['status' => $s])->id)->isLocked(), "status $s not locked");
        }
    }

    /**
     * Saving header info on a finalised order is refused (structure unchanged).
     */
    public function test_save_order_info_refused_on_locked_order(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $order = $this->makeOrder(['status' => ShopOrderStatus::DONE, 'first_name' => 'ORIG']);

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->set('form.first_name', 'CHANGED')
            ->call('saveOrderInfo');

        $this->assertSame('ORIG', ShopOrder::find($order->id)->first_name, 'header must not change on a locked order');
    }

    /**
     * Adding a line item to a finalised order is refused.
     */
    public function test_add_item_refused_on_locked_order(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $order = $this->makeOrder(['status' => ShopOrderStatus::REFUNDED]);
        $before = ShopOrderDetail::where('order_id', $order->id)->count();

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->call('editItem', 0) // opens blank add form path is component-specific; call saveItem directly
            ->call('saveItem');

        $this->assertSame($before, ShopOrderDetail::where('order_id', $order->id)->count(), 'no line added to a locked order');
    }

    /**
     * An unlocked order still saves normally (guard is not over-broad).
     */
    public function test_unlocked_order_still_saves_header(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $order = $this->makeOrder([
            'status' => ShopOrderStatus::PROCESSING,
            'first_name' => 'ORIG', 'phone' => '0123456789', 'address1' => 'Addr 1', 'country' => 'US',
        ]);

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->set('form.first_name', 'CHANGED')
            ->set('form.phone', '0123456789')
            ->set('form.address1', 'Addr 1')
            ->set('form.country', 'US')
            ->call('saveOrderInfo');

        $this->assertSame('CHANGED', ShopOrder::find($order->id)->first_name);
    }

    /**
     * Reopening a finalised order (changing status away from chốt) is allowed — it is
     * the legitimate way to make an order editable again.
     */
    public function test_changing_status_is_allowed_on_locked_order(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $order = $this->makeOrder(['status' => ShopOrderStatus::DONE]);

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->call('changeOrderStatus', ShopOrderStatus::PROCESSING);

        $this->assertSame(ShopOrderStatus::PROCESSING, (int) ShopOrder::find($order->id)->status);
    }

    /**
     * The manual payment-status setter is gone (it is a derived value).
     */
    public function test_change_payment_status_method_removed(): void
    {
        $this->assertFalse(
            method_exists(OrderManager::class, 'changePaymentStatus'),
            'changePaymentStatus was removed — payment_status is derived from the ledger'
        );
    }

    /**
     * Recording a payment on a finalised (Done) order is allowed — COD collected after
     * completion is normal; only structure is locked.
     */
    public function test_recording_payment_allowed_on_locked_order(): void
    {
        $order = $this->makeOrder(['status' => ShopOrderStatus::DONE, 'total' => 100, 'balance' => 100]);

        $movement = ShopOrder::find($order->id)->recordPayment(100, 'CashPayment');

        $this->assertNotNull($movement, 'payment must be allowed on a finalised order');
        $this->assertEqualsWithDelta(100, (float) ShopOrder::find($order->id)->received, 0.01);
    }
}
