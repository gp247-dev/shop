<?php

namespace GP247\Shop\Tests\Feature\Storefront;

use GP247\Shop\Events\CommerceEventOccurred;
use GP247\Shop\Front\Livewire\ProductCard;
use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopOrderDetail;
use GP247\Shop\Services\CartService;
use GP247\Shop\Services\Checkout\CheckoutState;
use GP247\Shop\Tracking\CommercePayload;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use GP247\Front\Testing\InteractsWithFrontStore;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * The storefront reports the four e-commerce events from server data — product page,
 * add to cart (plain form and Livewire), opening checkout, the thank-you page — as its own
 * Laravel event or browser event, never importing front; the payload carries only the
 * allowed fields, in the currency the visitor sees.
 *
 * @aidlc-unit storefront
 * @aidlc-story US-LW-commerce-tracking-events
 * @aidlc-adr tracking-consent_commerce-event-contract
 */
#[Group('tracking-consent')]
class CommerceTrackingEventsTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithFrontStore;
    use MakesShopProducts;

    /** Keys a payload may carry (NFR-SEC-tracking-no-personal-data). */
    private const PAYLOAD_KEYS = ['currency', 'items', 'shipping', 'tax', 'transaction_id', 'value'];

    private const ITEM_KEYS = ['item_brand', 'item_category', 'item_id', 'item_name', 'price', 'quantity'];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFrontStore();
        // WHY: the shop builds a payload only when someone listens (front's tracking layer);
        // a listener of the test's own keeps these tests independent of gp247/front.
        Event::listen(CommerceEventOccurred::class, static function (): void {
        });
        config(['gp247-config.shop.commerce_event_gate' => static fn (): bool => true]);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->tearDownFrontStore();
        parent::tearDown();
    }

    /**
     * Events of one type caught by Event::fake().
     *
     * @param string $type
     * @return array<int, CommerceEventOccurred>
     */
    private function reported(string $type): array
    {
        return Event::dispatched(CommerceEventOccurred::class, static fn (CommerceEventOccurred $e) => $e->type === $type)->map(static fn ($args) => $args[0])->values()->all();
    }

    /**
     * @param array<string, mixed> $payload
     * @return void
     */
    private function assertOnlyAllowedFields(array $payload): void
    {
        $keys = array_keys($payload);
        sort($keys);
        $this->assertSame([], array_values(array_diff($keys, self::PAYLOAD_KEYS)), 'payload keys');
        foreach ($payload['items'] as $item) {
            $itemKeys = array_keys($item);
            sort($itemKeys);
            $this->assertSame(self::ITEM_KEYS, $itemKeys);
        }
    }

    public function test_the_product_page_reports_view_item_with_the_price_the_visitor_sees(): void
    {
        Event::fake([CommerceEventOccurred::class]);
        $product = $this->makeProduct('ZZ Tracking Product', ['sku' => 'ZZTRK-' . uniqid(), 'price' => 25]);

        $this->get($product->getUrl())->assertOk();

        $events = $this->reported('view_item');
        $this->assertCount(1, $events);
        $payload = $events[0]->payload;
        $this->assertOnlyAllowedFields($payload);
        $this->assertSame($product->sku, $payload['items'][0]['item_id']);
        $this->assertSame('ZZ Tracking Product', $payload['items'][0]['item_name']);
        $this->assertEquals(round(gp247_currency_value($product->getFinalPrice()), 2), $payload['items'][0]['price']);
        $this->assertSame(gp247_currency_code(), $payload['currency']);
        $this->assertFalse($events[0]->nextPage);
    }

    public function test_adding_with_the_product_form_reports_add_to_cart_for_the_next_page(): void
    {
        Event::fake([CommerceEventOccurred::class]);
        $product = $this->makeProduct('ZZ Tracking Form');

        $this->post(route('cart.add'), ['product_id' => (string) $product->id, 'qty' => 3])->assertRedirect();

        $events = $this->reported('add_to_cart');
        $this->assertCount(1, $events);
        $this->assertTrue($events[0]->nextPage, 'the form redirects, so the next page prints it');
        $this->assertEquals(3, $events[0]->payload['items'][0]['quantity']);
    }

    public function test_adding_from_a_product_card_sends_a_browser_event_and_the_wishlist_does_not(): void
    {
        $product = $this->makeProduct('ZZ Tracking Card');

        Livewire::test(ProductCard::class, ['productId' => (string) $product->id])
            ->call('addToCart')
            ->assertDispatched('gp247-commerce', function (string $name, array $params) use ($product) {
                return $params['type'] === 'add_to_cart' && $params['payload']['items'][0]['item_id'] === $product->sku;
            });

        Livewire::test(ProductCard::class, ['productId' => (string) $product->id])
            ->call('toggleList', 'wishlist')
            ->assertNotDispatched('gp247-commerce');
    }

    public function test_opening_checkout_reports_begin_checkout_with_the_cart_lines(): void
    {
        Event::fake([CommerceEventOccurred::class]);
        $product = $this->makeProduct('ZZ Tracking Checkout', ['price' => 12]);
        (new CartService())->instance('default')->add(['id' => (string) $product->id, 'name' => 'ZZ Tracking Checkout', 'qty' => 2, 'storeId' => $this->storeId()]);
        app(CheckoutState::class)->setStoreId($this->storeId());

        $this->get(gp247_route_front('checkout'));

        $events = $this->reported('begin_checkout');
        $this->assertCount(1, $events);
        $payload = $events[0]->payload;
        $this->assertOnlyAllowedFields($payload);
        $this->assertEquals(2, $payload['items'][0]['quantity']);
        $this->assertEquals(round(gp247_currency_value(12) * 2, 2), $payload['value']);
    }

    public function test_the_thank_you_page_reports_purchase_once_with_the_order_values_and_no_customer_data(): void
    {
        Event::fake([CommerceEventOccurred::class]);
        $product = $this->makeProduct('ZZ Tracking Order');
        $order = ShopOrder::create([
            'customer_id' => 0, 'currency' => 'EUR', 'exchange_rate' => 0.9,
            'email' => 'buyer-' . uniqid() . '@example.com', 'first_name' => 'Secret', 'last_name' => 'Buyer',
            'phone' => '0900000000', 'address1' => '1 Private Street',
            'status' => 1, 'payment_status' => 1, 'shipping_status' => 1,
            'subtotal' => 90, 'tax' => 0, 'shipping' => 10, 'other_fee' => 0, 'total' => 100,
            'store_id' => $this->storeId(),
        ]);
        ShopOrderDetail::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'name' => 'ZZ Tom &amp; Jerry', 'price' => 45, 'qty' => 2,
            'store_id' => $this->storeId(), 'total_price' => 90, 'tax' => 0, 'sku' => $product->sku, 'currency' => 'EUR', 'exchange_rate' => 0.9,
        ]);

        $this->withSession(['orderID' => $order->id, 'orderCompleted' => (string) $order->id])->get(gp247_route_front('order.success'))->assertOk();
        session()->forget(['orderID', 'orderCompleted']);
        $this->get(gp247_route_front('order.success'))->assertRedirect();

        $events = $this->reported('purchase');
        $this->assertCount(1, $events, 'reloading the thank-you page reports nothing');
        $payload = $events[0]->payload;
        $this->assertOnlyAllowedFields($payload);
        $this->assertSame((string) $order->id, $payload['transaction_id']);
        $this->assertSame('EUR', $payload['currency']);
        $this->assertEquals(100, $payload['value']);
        $this->assertEquals(0, $payload['tax']);
        $this->assertEquals(10, $payload['shipping']);
        $this->assertEquals(45, $payload['items'][0]['price']);
        $this->assertSame('ZZ Tom & Jerry', $payload['items'][0]['item_name'], 'same name as on the other events');
        $encoded = json_encode($payload);
        foreach (['buyer-', 'Secret', 'Buyer', '0900000000', 'Private Street'] as $personal) {
            $this->assertStringNotContainsString($personal, $encoded);
        }
    }

    public function test_a_broken_payload_never_breaks_the_page_and_nothing_is_built_without_a_listener(): void
    {
        CommerceEventOccurred::emit('view_item', static function (): array {
            throw new \RuntimeException('broken payload');
        });

        $built = false;
        Event::forget(CommerceEventOccurred::class);
        CommerceEventOccurred::emit('view_item', static function () use (&$built): array {
            $built = true;

            return [];
        });
        $this->assertFalse($built, 'no listener (front absent) ⇒ no payload work');
    }

    public function test_only_the_four_event_types_exist(): void
    {
        $this->assertSame(['view_item', 'add_to_cart', 'begin_checkout', 'purchase'], CommercePayload::TYPES);
        $this->expectException(\InvalidArgumentException::class);
        new CommerceEventOccurred('add_to_wishlist', []);
    }

    public function test_a_replayed_confirm_shows_the_thank_you_page_without_reporting_a_purchase(): void
    {
        Event::fake([CommerceEventOccurred::class]);
        $order = ShopOrder::create([
            'customer_id' => 0, 'currency' => 'USD', 'exchange_rate' => 1, 'email' => 'replay-' . uniqid() . '@example.com', 'first_name' => 'ZZ Replay',
            'status' => 1, 'payment_status' => 1, 'shipping_status' => 1, 'subtotal' => 10, 'tax' => 0, 'shipping' => 0,
            'other_fee' => 0, 'total' => 10, 'store_id' => $this->storeId(),
        ]);

        // Back from a payment gateway + confirm again: the order exists but was not completed now.
        $this->withSession(['orderID' => $order->id])->get(gp247_route_front('order.success'))->assertOk();

        $this->assertSame([], $this->reported('purchase'));
    }

    public function test_no_payload_is_built_when_the_tracking_layer_says_it_has_no_service(): void
    {
        Event::fake([CommerceEventOccurred::class]);
        config(['gp247-config.shop.commerce_event_gate' => static fn (): bool => false]);
        $product = $this->makeProduct('ZZ Tracking Gate');

        $this->get($product->getUrl())->assertOk();
        Livewire::test(ProductCard::class, ['productId' => (string) $product->id])
            ->call('addToCart')
            ->assertNotDispatched('gp247-commerce');

        $this->assertSame([], $this->reported('view_item'));
    }
}
