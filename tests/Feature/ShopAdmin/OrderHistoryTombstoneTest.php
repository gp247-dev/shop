<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopOrderHistory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopOrders;
use GP247\Shop\Tests\TestCase;

/**
 * Order history survives the order and holds long content.
 *
 * content was varchar(300) (truncated long entries) and boot deleting() wiped history —
 * the most destructive action left no trace. Now content is text and deleting an order
 * KEEPS its history plus an "Order deleted" line (tombstone).
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-order-audit-trail
 * @aidlc-adr shop-admin_order-history-tombstone
 */
class OrderHistoryTombstoneTest extends TestCase
{
    use DatabaseTransactions;
    use MakesShopOrders;

    /**
     * Deleting an order (money-free) keeps its history and appends "Order deleted".
     */
    public function test_deleting_order_keeps_history_with_tombstone(): void
    {
        $order = $this->makeOrder();
        (new ShopOrder)->addOrderHistory([
            'order_id' => $order->id,
            'content' => 'New order',
            'admin_id' => 0,
            'order_status_id' => 1,
        ]);

        ShopOrder::find($order->id)->delete();

        $this->assertNull(ShopOrder::find($order->id), 'order is hard-deleted');
        $rows = ShopOrderHistory::where('order_id', $order->id)->pluck('content')->all();
        $this->assertContains('New order', $rows, 'prior history is kept');
        $this->assertContains('Order deleted', $rows, 'a tombstone line is written');
    }

    /**
     * content holds text longer than the old 300-char cap without truncation.
     */
    public function test_long_history_content_is_not_truncated(): void
    {
        $order = $this->makeOrder();
        $long = str_repeat('Very long address change entry. ', 20); // ~640 chars

        (new ShopOrder)->addOrderHistory([
            'order_id' => $order->id,
            'content' => $long,
            'admin_id' => 0,
            'order_status_id' => 1,
        ]);

        $stored = ShopOrderHistory::where('order_id', $order->id)->where('content', $long)->first();
        $this->assertNotNull($stored, 'long content stored intact');
        $this->assertSame(strlen($long), strlen((string) $stored->content));
    }
}
