<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopOrderTotal;
use GP247\Shop\Models\ShopOrderTransaction;
use GP247\Shop\Models\ShopProduct;
use GP247\Shop\Services\Catalog\WriteContext;
use GP247\Shop\Services\Order\HistoricalOrderWriter;
use GP247\Shop\Services\ShopEvents;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Group;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopOrders;
use GP247\Shop\Tests\TestCase;

/**
 * An order that already happened in another system is written as an archive: lines, totals,
 * status history and the money received as the source says, with none of the side effects of
 * a real order (no stock taken, no mail, no promotion, no sync event).
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-historical-order-write
 */
#[Group('shop-admin')]
class HistoricalOrderWriteTest extends TestCase
{
    use DatabaseTransactions;
    use MakesShopOrders;

    /**
     * @param array<string, mixed> $order
     * @param list<array<string, mixed>>|null $lines
     * @return array<string, mixed>
     */
    private function payload(array $order = [], ?array $lines = null, array $extra = []): array
    {
        return [
            'order' => array_merge([
                'first_name' => 'Hanako', 'last_name' => 'Yamada', 'email' => 'hanako-' . uniqid() . '@example.test',
                'phone' => '0900000000', 'address1' => '1-2-3 Shibuya', 'country' => 'JP',
                'currency' => 'JPY', 'exchange_rate' => 150,
                'subtotal' => 3000, 'tax' => 300, 'shipping' => 500, 'discount' => 200, 'other_fee' => 0, 'total' => 3600,
                'received' => 3600,
                'status' => 5, 'shipping_status' => 3,
                'payment_method' => 'cod', 'shipping_method' => 'yamato',
                'created_at' => '2024-03-01 10:00:00',
            ], $order),
            'lines' => $lines ?? [
                ['sku' => 'OLD-1', 'name' => 'Tea set', 'price' => 1500, 'qty' => 2, 'total_price' => 3000, 'tax' => 300, 'tax_rate' => 10],
            ],
            'history' => $extra['history'] ?? [
                ['order_status_id' => 1, 'content' => 'Placed on the old shop', 'add_date' => '2024-03-01 10:00:00'],
                ['order_status_id' => 5, 'content' => 'Delivered', 'add_date' => '2024-03-04 09:00:00'],
            ],
            'source' => 'woo',
        ];
    }

    private function writer(): HistoricalOrderWriter
    {
        return new HistoricalOrderWriter;
    }

    private function context(): WriteContext
    {
        return new WriteContext(GP247_STORE_ID_ROOT, WriteContext::ACTOR_IMPORT);
    }

    public function test_an_imported_order_keeps_its_lines_totals_history_and_money_as_the_source_says(): void
    {
        $order = $this->writer()->write($this->context(), $this->payload());

        $order = ShopOrder::with(['details', 'orderTotal', 'history'])->find($order->id);
        $this->assertSame('JPY', $order->currency);
        $this->assertEquals(3600, (float) $order->total);
        $this->assertEquals(3600, (float) $order->received);
        $this->assertEquals(0, (float) $order->balance);
        $this->assertSame('2024-03-01 10:00:00', (string) $order->created_at);
        $this->assertCount(1, $order->details);
        $this->assertSame('OLD-1', $order->details[0]->sku);
        $this->assertEquals(3600, (float) $order->orderTotal->firstWhere('code', 'total')->value);
        $this->assertEquals(200, (float) $order->orderTotal->firstWhere('code', 'discount')->value, 'money is stored as a positive magnitude');
        $this->assertCount(2, $order->history);
        $this->assertSame(3600.0, ShopOrderTransaction::netReceived($order->id), 'the money received is in the payment ledger');
    }

    public function test_an_imported_order_takes_no_stock_and_deleting_it_gives_no_stock_back(): void
    {
        $product = $this->makeProduct(['stock' => 10]);

        $order = $this->writer()->write($this->context(), $this->payload([], [
            ['product_id' => $product->id, 'sku' => $product->sku, 'name' => 'Linked', 'price' => 1500, 'qty' => 2, 'total_price' => 3000, 'tax' => 300, 'tax_rate' => 10],
        ]));
        $this->assertEquals(10, (float) ShopProduct::find($product->id)->stock);

        ShopOrder::find($order->id)->delete();
        $this->assertEquals(10, (float) ShopProduct::find($product->id)->stock);
    }

    public function test_an_imported_order_is_marked_as_imported_and_sends_no_sync_event(): void
    {
        $before = ShopEvents::active();
        $order = $this->writer()->write($this->context(), $this->payload());

        if (ShopOrder::supportsChannel()) {
            $this->assertSame(HistoricalOrderWriter::CHANNEL, ShopOrder::find($order->id)->channel);
        }
        $this->assertSame($before, ShopEvents::active(), 'event recording is back to what it was');
        if (\Illuminate\Support\Facades\Schema::connection(GP247_DB_CONNECTION)->hasTable(GP247_DB_PREFIX . 'api_event')) {
            $this->assertSame(0, \Illuminate\Support\Facades\DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'api_event')->where('subject_id', $order->id)->count());
        }
    }

    public function test_a_line_whose_product_is_unknown_keeps_its_name_and_sku(): void
    {
        $order = $this->writer()->write($this->context(), $this->payload());

        $line = ShopOrder::find($order->id)->details->first();
        $this->assertSame(HistoricalOrderWriter::NO_PRODUCT, (string) $line->product_id);
        $this->assertSame('Tea set', $line->name);
        $this->assertSame(0, (int) $order->customer_id);
    }

    public function test_an_order_status_that_does_not_exist_is_refused_and_nothing_is_written(): void
    {
        $count = ShopOrder::count();

        try {
            $this->writer()->write($this->context(), $this->payload(['status' => 999]));
            $this->fail('An unknown status was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('order.status', $e->errors());
        }
        $this->assertSame($count, ShopOrder::count());
    }

    public function test_negative_money_an_order_without_lines_and_a_line_without_name_are_refused(): void
    {
        foreach ([
            'order.discount' => $this->payload(['discount' => -5]),
            'lines' => $this->payload([], []),
            'lines.0.name' => $this->payload([], [['sku' => 'X', 'name' => '', 'price' => 1, 'qty' => 1, 'total_price' => 1]]),
        ] as $field => $payload) {
            try {
                $this->writer()->write($this->context(), $payload);
                $this->fail($field . ' was accepted.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }
    }

    public function test_an_order_paid_in_part_keeps_the_balance_still_due(): void
    {
        $order = $this->writer()->write($this->context(), $this->payload(['received' => 1000]));

        $order = ShopOrder::find($order->id);
        $this->assertEquals(1000, (float) $order->received);
        $this->assertEquals(2600, (float) $order->balance);
    }

    public function test_an_order_of_a_known_customer_keeps_the_customer_id_on_the_order_and_its_history(): void
    {
        $customer = \GP247\Shop\Models\ShopCustomer::create([
            'first_name' => 'Taro', 'email' => 'taro-' . uniqid() . '@example.test', 'password' => '', 'store_id' => GP247_STORE_ID_ROOT,
        ]);

        $order = $this->writer()->write($this->context(), $this->payload(['customer_id' => (string) $customer->id]));

        $order = ShopOrder::with(['history', 'details'])->find($order->id);
        $this->assertSame((string) $customer->id, (string) $order->customer_id);
        $this->assertSame(['Yamada', '0900000000', '1-2-3 Shibuya', 'cod', 'yamato'],
            [(string) $order->last_name, (string) $order->phone, (string) $order->address1, (string) $order->payment_method, (string) $order->shipping_method],
            'every order field of the source is kept');
        $this->assertSame([(string) $customer->id], $order->history->pluck('customer_id')->map(fn ($v) => (string) $v)->unique()->values()->all());
    }

    public function test_undoing_an_import_removes_the_archived_order_with_its_money_but_never_a_real_order(): void
    {
        $product = $this->makeProduct(['stock' => 10]);
        $archived = $this->writer()->write($this->context(), $this->payload([], [
            ['product_id' => $product->id, 'sku' => $product->sku, 'name' => 'Linked', 'price' => 1500, 'qty' => 2, 'total_price' => 3000],
        ]));
        $this->assertTrue(ShopOrderTransaction::where('order_id', $archived->id)->exists());
        $this->assertSame((string) $product->id, (string) $archived->details()->value('product_id'), 'a line keeps its product');

        $this->assertNull($this->writer()->remove($this->context(), (string) $archived->id));

        $this->assertNull(ShopOrder::find($archived->id));
        $this->assertFalse(ShopOrderTransaction::withTrashed()->where('order_id', $archived->id)->exists());
        $this->assertSame(0, \GP247\Shop\Models\ShopOrderDetail::where('order_id', $archived->id)->count());
        $this->assertSame(0, ShopOrderTotal::where('order_id', $archived->id)->count());
        $this->assertSame(0, \GP247\Shop\Models\ShopOrderHistory::where('order_id', $archived->id)->count());
        $this->assertEquals(10, (float) ShopProduct::find($product->id)->stock);

        $real = $this->makeOrder();
        $this->assertSame('not_archived', $this->writer()->remove($this->context(), (string) $real->id));
        $this->assertNotNull(ShopOrder::find($real->id));
        $this->assertSame('not_found', $this->writer()->remove($this->context(), 'OD-missing'));
    }

    public function test_the_order_totals_follow_the_one_sign_convention(): void
    {
        $order = $this->writer()->write($this->context(), $this->payload());

        $totals = ShopOrder::find($order->id)->orderTotal->pluck('value', 'code');
        $computed = (float) $totals['subtotal'] + (float) $totals['tax']
            + ShopOrderTotal::signOf('shipping') * (float) $totals['shipping']
            + ShopOrderTotal::signOf('discount') * (float) $totals['discount']
            + ShopOrderTotal::signOf('other_fee') * (float) $totals['other_fee'];
        $this->assertEquals((float) $totals['total'], $computed);
    }
}
