<?php

namespace GP247\Shop\Tests\Feature\ApiCommerce;

use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopProduct;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use GP247\Shop\Tests\Feature\ApiCommerce\Support\ApiCommerceFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * An app places the order of one store of the cart through API v2, exactly once per
 * Idempotency-Key: a resend gives back the same order (no second order, no second stock
 * movement), a price that moved since the totals were shown is never charged silently,
 * and the store owner decides whether ordering through the API is open at all.
 *
 * @aidlc-unit api-commerce
 * @aidlc-story US-api-commerce-place-order
 * @aidlc-story US-api-commerce-checkout-switch
 * @aidlc-story US-api-commerce-multi-store-checkout
 */
#[Group('api-commerce')]
class ApiPlaceOrderTest extends TestCase
{
    use DatabaseTransactions;
    use ApiCommerceFixtures;

    private string $token;
    private ShopProduct $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useSellableShop();
        $this->useMinimalCustomerForm();
        $this->setShopConfig(['use_shipping' => '0', 'use_payment' => '0']);
        $this->setApiSwitch('api_v2_front_checkout', true);
        $this->token = $this->tokenFor($this->makeCustomer(), ['user']);
        $this->product = $this->makeProduct(['price' => 25, 'tax_id' => 0, 'stock' => 10]);
    }

    /**
     * Cart with 2 × product, address set, totals shown.
     *
     * @return void
     */
    private function readyToOrder(): void
    {
        $this->cartCall('POST', '/cart/items', $this->token, null, ['product_id' => (string) $this->product->id, 'qty' => 2])->assertStatus(201);
        $this->cartCall('PUT', '/checkout/address', $this->token, null,
            ['first_name' => 'ZZ Buyer', 'email' => 'zz-place@example.com', 'address1' => '1 ZZ street'])->assertOk();
        $this->cartCall('GET', '/checkout/totals', $this->token)->assertOk();
    }

    /**
     * @param string|null $key
     * @param array       $data
     * @return \Illuminate\Testing\TestResponse
     */
    private function place(?string $key, array $data = [])
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withHeader('Authorization', 'Bearer ' . $this->token);
        if ($key !== null) {
            $this->withHeader('Idempotency-Key', $key);
        }

        return $this->json('POST', $this->v2Front() . '/checkout/place', $data);
    }

    public function test_placing_an_order_needs_an_idempotency_key(): void
    {
        $this->readyToOrder();

        $this->place(null)->assertStatus(400)->assertJson(['code' => 'idempotency_key_required']);
        $this->place('short')->assertStatus(400)->assertJson(['code' => 'idempotency_key_required']);
    }

    public function test_an_order_is_placed_for_the_cart_and_the_cart_line_leaves(): void
    {
        $this->readyToOrder();

        $response = $this->place('zz-key-' . uniqid())->assertStatus(201);

        $order = ShopOrder::find($response->json('data.order.id'));
        $this->assertNotNull($order);
        $this->assertEquals(50, (float) $order->total);
        $this->assertSame('ZZ Buyer', $order->first_name);
        $this->assertSame('none', $response->json('data.payment.type'));
        $this->assertEquals(8, (float) ShopProduct::find($this->product->id)->stock, 'stock is taken once');
        $this->assertSame(0, $this->cartCall('GET', '/cart', $this->token)->json('data.line_count'), 'the ordered line leaves the cart');
        if (Schema::connection(GP247_DB_CONNECTION)->hasColumn(GP247_DB_PREFIX . 'shop_order', 'channel')) {
            $this->assertSame('api', $order->channel);
        }
    }

    public function test_resending_the_same_key_gives_back_the_same_order_and_takes_no_more_stock(): void
    {
        $this->readyToOrder();
        $key = 'zz-key-' . uniqid();
        $first = $this->place($key)->assertStatus(201)->json('data.order.id');

        $again = $this->place($key)->assertOk();

        $this->assertSame($first, $again->json('data.order.id'));
        $this->assertSame(1, ShopOrder::where('id', $first)->count());
        $this->assertEquals(8, (float) ShopProduct::find($this->product->id)->stock);
    }

    public function test_the_same_key_for_another_store_is_refused(): void
    {
        $this->readyToOrder();
        $key = 'zz-key-' . uniqid();
        $orderId = $this->place($key)->assertStatus(201)->json('data.order.id');
        ShopOrder::where('id', $orderId)->update(['store_id' => 'ZZ-OTHER-STORE']);

        $this->place($key, ['store_id' => (string) GP247_STORE_ID_ROOT])->assertStatus(409)->assertJson(['code' => 'idempotency_key_reused']);
        $this->place($key)->assertOk()->assertJson(['data' => ['order' => ['id' => $orderId]]]);
    }

    public function test_a_price_that_moved_since_the_totals_were_shown_is_never_charged(): void
    {
        $this->readyToOrder();
        ShopProduct::where('id', $this->product->id)->update(['price' => 30]);

        $response = $this->place('zz-key-' . uniqid())->assertStatus(409)->assertJson(['code' => 'price_changed']);

        $this->assertEquals(60, collect($response->json('totals.lines'))->keyBy('code')['total']['value'], 'the new total comes back');
        $this->assertSame(0, ShopOrder::where('email', 'zz-place@example.com')->count());
        $this->place('zz-key-' . uniqid())->assertStatus(201);
    }

    public function test_an_order_needs_an_address_and_the_methods_the_shop_uses(): void
    {
        $this->cartCall('POST', '/cart/items', $this->token, null, ['product_id' => (string) $this->product->id, 'qty' => 1]);
        $this->place('zz-key-' . uniqid())->assertStatus(422)->assertJson(['code' => 'address_required']);

        $this->cartCall('PUT', '/checkout/address', $this->token, null, ['first_name' => 'ZZ', 'email' => 'zz-m@example.com', 'address1' => '1 ZZ']);
        $this->setShopConfig(['use_payment' => '1']);
        $this->place('zz-key-' . uniqid())->assertStatus(422)->assertJson(['code' => 'payment_method_required']);
        $this->setShopConfig(['use_payment' => '0', 'use_shipping' => '1']);
        $this->place('zz-key-' . uniqid())->assertStatus(422)->assertJson(['code' => 'shipping_method_required']);
    }

    public function test_a_product_below_its_minimum_quantity_cannot_be_ordered(): void
    {
        ShopProduct::where('id', $this->product->id)->update(['minimum' => 5]);
        $this->readyToOrder();

        $this->place('zz-key-' . uniqid())->assertStatus(422)->assertJson(['code' => 'minimum_not_met']);
    }

    public function test_a_store_without_lines_in_the_cart_cannot_be_checked_out(): void
    {
        $this->readyToOrder();

        $this->place('zz-key-' . uniqid(), ['store_id' => 'ZZ-NO-SUCH-STORE'])->assertStatus(422)->assertJson(['code' => 'cart_empty']);
    }

    public function test_ordering_through_the_api_is_closed_until_the_store_owner_opens_it(): void
    {
        $this->readyToOrder();
        $this->setApiSwitch('api_v2_front_checkout', false);

        $this->place('zz-key-' . uniqid())->assertStatus(403)->assertJson(['code' => 'api_checkout_disabled']);
        $this->cartCall('GET', '/checkout/totals', $this->token)->assertOk();
    }

    public function test_running_the_upgrade_again_keeps_the_owners_choice_and_the_channel_column(): void
    {
        $this->assertTrue(Schema::connection(GP247_DB_CONNECTION)->hasColumn(GP247_DB_PREFIX . 'shop_order', 'channel'),
            'the upgrade migration has run on this database');
        $migration = require base_path('vendor/gp247/shop/src/Admin/Database/Migrations/upgrade/2026_10_30_120000_install_api_commerce.php');

        $migration->up();

        $value = \DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config')
            ->where('key', 'api_v2_front_checkout')->where('store_id', GP247_STORE_ID_GLOBAL)->value('value');
        $this->assertSame('1', (string) $value, 'the switch the owner opened stays open');
    }

    public function test_an_order_placed_through_the_api_is_marked_as_coming_from_the_api(): void
    {
        $this->readyToOrder();

        $id = $this->place('zz-key-' . uniqid())->assertStatus(201)->json('data.order.id');

        $this->assertSame('api', ShopOrder::find($id)->channel);
    }

    public function test_an_order_placed_through_the_api_carries_the_store_currency(): void
    {
        $this->readyToOrder();
        // WHY: the currency is a static set per request; a website request run earlier in the
        // same process must not be what gives the API its currency.
        \GP247\Shop\Models\ShopCurrency::setCode(null);

        $response = $this->place('zz-key-' . uniqid())->assertStatus(201);

        $expected = (string) gp247_store_info('currency');
        $expected = array_key_exists($expected, gp247_currency_all_active()) ? $expected : (string) array_key_first(gp247_currency_all_active());
        $this->assertSame($expected, (string) ShopOrder::find($response->json('data.order.id'))->currency);
        $this->assertSame($expected, $response->json('data.order.currency'));
    }

    public function test_too_many_orders_in_a_minute_are_throttled(): void
    {
        config(['gp247-config.api.throttle.checkout_place' => 1]);
        $this->readyToOrder();

        $this->place('zz-key-' . uniqid())->assertStatus(201);
        $this->place('zz-key-' . uniqid())->assertStatus(429);
    }
}
