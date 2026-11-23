<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Admin\Livewire\OrderManager;
use GP247\Shop\Admin\Models\AdminOrder;
use GP247\Shop\Models\ShopOrder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopOrders;
use GP247\Shop\Tests\TestCase;

/**
 * Two readability fixes on the order detail screen.
 *
 * The history timeline used to open every entry with a raw timestamp
 * (`2026-08-29T10:03:00.000000Z`) — the least useful thing an audit line can lead
 * with, since the reader already knows roughly when and came to find out WHO. The
 * actor id was recorded all along; it was simply never resolved to a name.
 *
 * The money form had three unlabelled inputs stacked in a column, so nothing on
 * screen said which one was the amount and which the date. Guessing wrong there
 * writes money.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-003
 * @aidlc-adr shop_order-payment-ledger
 */
class OrderDetailReadabilityTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use MakesShopOrders;

    /**
     * An order of 100 with its totals synced, ready to receive money.
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
     * A change carrying an admin id is attributed to that admin BY NAME.
     *
     * The history row is written directly rather than through the screen: the test
     * harness authenticates with a fake admin that has no database row, so a change
     * made through Livewire records id 0. What is under test here is the resolution
     * from a recorded id to a human name, and that needs a real admin to resolve to.
     */
    public function test_history_shows_who_made_the_change(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $order = $this->orderOf100();

        $adminId = gp247_uuid();
        DB::connection(GP247_DB_CONNECTION)
            ->table(GP247_DB_PREFIX . 'admin_user')
            ->insert([
                'id' => $adminId,
                'username' => 'zz-hist-' . uniqid(),
                'password' => '-',
                'name' => 'ZZ History Admin',
                'email' => 'zz-hist-' . uniqid() . '@example.com',
                'status' => 1,
            ]);

        DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'shop_order_history')->insert([
            'id' => gp247_generate_id('ODH'),
            'order_id' => $order->id,
            'content' => 'Change status',
            'admin_id' => $adminId,
            'customer_id' => 0,
            'order_status_id' => 1,
            'add_date' => gp247_time_now(),
        ]);

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->assertSee('ZZ History Admin');
    }

    /**
     * An entry with no actor recorded reads as "System" rather than as a blank —
     * storefront events and anything automated land here.
     */
    public function test_history_without_an_actor_reads_as_system(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $order = $this->orderOf100();

        DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'shop_order_history')->insert([
            'id' => gp247_generate_id('ODH'),
            'order_id' => $order->id,
            'content' => 'New order',
            'admin_id' => 0,
            'customer_id' => 0,
            'order_status_id' => 1,
            'add_date' => gp247_time_now(),
        ]);

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->assertSee(gp247_language_render('admin.order.history_actor_system'));
    }

    /**
     * The raw ISO timestamp is gone from the timeline — that is the thing being
     * replaced, so its absence is worth asserting rather than assuming.
     */
    public function test_history_no_longer_prints_a_raw_timestamp(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $order = $this->orderOf100();

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->call('changeOrderStatus', 2)
            ->assertDontSee('.000000Z');
    }

    /**
     * Every input in the money form is labelled, and the panel says which direction
     * it is recording.
     */
    public function test_the_money_form_labels_every_field(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $order = $this->orderOf100();

        $view = Livewire::test(OrderManager::class, ['id' => (string) $order->id]);

        $view->assertSee(gp247_language_render('admin.order.payment_amount'));
        $view->assertSee(gp247_language_render('admin.order.payment_date'));
        $view->assertSee(gp247_language_render('admin.order.payment_method_label'));
        // The date hint carries the one fact that changes reporting: this is the day
        // the money moved, not the order date.
        $view->assertSee(gp247_language_render('admin.order.payment_date_hint'));
    }
}
