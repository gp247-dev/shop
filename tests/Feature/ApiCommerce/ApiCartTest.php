<?php

namespace GP247\Shop\Tests\Feature\ApiCommerce;

use GP247\Shop\Models\ShopProduct;
use GP247\Shop\Services\CartService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Group;
use GP247\Shop\Tests\Feature\ApiCommerce\Support\ApiCommerceFixtures;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\ConfiguresQtyDecimal;
use GP247\Shop\Tests\TestCase;

/**
 * An app reads and changes the shopper's cart through API v2 with the website's rules:
 * prices and attribute surcharges decided by the server, quantity rules, one line per
 * variant, a line cap, lines that can no longer be bought flagged, and the signed-in
 * customer's cart shared with the website.
 *
 * @aidlc-unit api-commerce
 * @aidlc-story US-api-commerce-cart-view
 * @aidlc-story US-api-commerce-cart-items
 * @aidlc-story US-CMP-stock-zero-not-for-sale
 */
#[Group('api-commerce')]
class ApiCartTest extends TestCase
{
    use DatabaseTransactions;
    use ApiCommerceFixtures;
    use ConfiguresQtyDecimal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useSellableShop();
        $this->resetQtyDecimalCache();
        $this->setQtyDecimalEnabled(false);
    }

    public function test_a_signed_in_customer_sees_in_the_app_the_cart_they_filled_on_the_website(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['price' => 40]);
        $this->actingAs($customer, 'customer');
        (new CartService())->instance('default')->add(['id' => (string) $product->id, 'name' => 'ZZ', 'qty' => 2, 'storeId' => (string) GP247_STORE_ID_ROOT]);

        $lines = $this->linesOf($this->cartCall('GET', '/cart', $this->tokenFor($customer, ['user']))->assertOk());

        $this->assertCount(1, $lines);
        $this->assertSame((string) $product->id, (string) $lines[0]['product_id']);
        $this->assertEquals(2, $lines[0]['qty']);
        $this->assertEquals(40, $lines[0]['unit_price']);
    }

    public function test_a_guest_without_a_cart_token_sees_an_empty_cart_and_gets_no_token(): void
    {
        $response = $this->cartCall('GET', '/cart')->assertOk();

        $this->assertSame([], $response->json('data.stores'));
        $this->assertSame(0, $response->json('data.line_count'));
        $this->assertFalse($response->headers->has('X-Cart-Token'));
    }

    public function test_attribute_surcharges_are_priced_by_the_server_not_by_the_app(): void
    {
        $product = $this->makeProduct(['price' => 100]);
        $group = $this->addAttributeGroup($product, ['Red' => 5, 'Blue' => 8]);

        $response = $this->cartCall('POST', '/cart/items', null, null,
            ['product_id' => (string) $product->id, 'qty' => 1, 'options' => [(string) $group->id => 'Red__999']])->assertStatus(201);

        $line = $this->linesOf($response)[0];
        $this->assertEquals(105, $line['unit_price']);
        $this->assertEquals(105, $response->json('data.stores.0.subtotal'));
        $this->assertSame('Red', $line['options'][0]['name']);
    }

    public function test_a_product_with_attributes_needs_every_attribute_group_chosen(): void
    {
        $product = $this->makeProduct();
        $this->addAttributeGroup($product, ['S' => 0]);
        $this->addAttributeGroup($product, ['Red' => 0]);

        $this->cartCall('POST', '/cart/items', null, null, ['product_id' => (string) $product->id, 'qty' => 1])
            ->assertStatus(422)->assertJsonValidationErrors(['options']);
    }

    public function test_a_negative_or_fractional_quantity_is_refused(): void
    {
        $product = $this->makeProduct();

        $this->cartCall('POST', '/cart/items', null, null, ['product_id' => (string) $product->id, 'qty' => -1])
            ->assertStatus(422)->assertJsonValidationErrors(['qty']);
        $this->cartCall('POST', '/cart/items', null, null, ['product_id' => (string) $product->id, 'qty' => 1.5])
            ->assertStatus(422)->assertJsonValidationErrors(['qty']);
    }

    public function test_adding_the_same_variant_again_raises_the_quantity_of_the_same_line(): void
    {
        $customer = $this->makeCustomer();
        $token = $this->tokenFor($customer, ['user']);
        $product = $this->makeProduct();

        $this->cartCall('POST', '/cart/items', $token, null, ['product_id' => (string) $product->id, 'qty' => 1])->assertStatus(201);
        $lines = $this->linesOf($this->cartCall('POST', '/cart/items', $token, null, ['product_id' => (string) $product->id, 'qty' => 2]));

        $this->assertCount(1, $lines);
        $this->assertEquals(3, $lines[0]['qty']);
    }

    public function test_the_cart_refuses_a_line_beyond_its_line_limit(): void
    {
        $this->assertSame(100, (int) config('gp247-config.shop.api_cart_max_lines', 100), 'the default limit is 100 lines');
        config(['gp247-config.shop.api_cart_max_lines' => 2]);
        $token = $this->tokenFor($this->makeCustomer(), ['user']);
        foreach ([1, 2] as $i) {
            $this->cartCall('POST', '/cart/items', $token, null, ['product_id' => (string) $this->makeProduct()->id, 'qty' => 1])->assertStatus(201);
        }

        $this->cartCall('POST', '/cart/items', $token, null, ['product_id' => (string) $this->makeProduct()->id, 'qty' => 1])
            ->assertStatus(422)->assertJson(['code' => 'cart_line_limit']);
    }

    public function test_more_than_the_stock_cannot_be_put_in_the_cart_when_the_shop_does_not_sell_out_of_stock(): void
    {
        $product = $this->makeProduct(['stock' => 2]);

        $this->cartCall('POST', '/cart/items', null, null, ['product_id' => (string) $product->id, 'qty' => 3])
            ->assertStatus(422)->assertJson(['code' => 'insufficient_stock']);
    }

    public function test_an_out_of_stock_product_cannot_be_put_in_the_api_cart(): void
    {
        $product = $this->makeProduct(['stock' => 0]);

        $this->cartCall('POST', '/cart/items', null, null, ['product_id' => (string) $product->id, 'qty' => 1])
            ->assertStatus(422)->assertJson(['code' => 'product_not_for_sale']);
    }

    public function test_setting_a_line_to_zero_removes_it_and_deleting_a_line_empties_the_cart(): void
    {
        $token = $this->tokenFor($this->makeCustomer(), ['user']);
        $first = $this->makeProduct();
        $second = $this->makeProduct();
        $this->cartCall('POST', '/cart/items', $token, null, ['product_id' => (string) $first->id, 'qty' => 1]);
        $lines = $this->linesOf($this->cartCall('POST', '/cart/items', $token, null, ['product_id' => (string) $second->id, 'qty' => 1]));
        $ids = array_column($lines, 'id', 'product_id');

        $after = $this->linesOf($this->cartCall('PATCH', '/cart/items/' . $ids[(string) $first->id], $token, null, ['qty' => 0])->assertOk());
        $this->assertSame([(string) $second->id], array_map('strval', array_column($after, 'product_id')));

        $this->cartCall('DELETE', '/cart/items/' . $ids[(string) $second->id], $token)->assertOk()->assertJson(['data' => ['line_count' => 0]]);
    }

    public function test_a_line_of_another_shoppers_cart_cannot_be_changed(): void
    {
        $owner = $this->tokenFor($this->makeCustomer(), ['user']);
        $line = $this->linesOf($this->cartCall('POST', '/cart/items', $owner, null, ['product_id' => (string) $this->makeProduct()->id, 'qty' => 1]))[0];
        $intruder = $this->tokenFor($this->makeCustomer(), ['user']);

        $this->cartCall('PATCH', '/cart/items/' . $line['id'], $intruder, null, ['qty' => 5])->assertStatus(404);
        $this->cartCall('DELETE', '/cart/items/' . $line['id'], $intruder)->assertStatus(404);
        $this->assertEquals(1, $this->linesOf($this->cartCall('GET', '/cart', $owner))[0]['qty']);
    }

    public function test_a_line_that_can_no_longer_be_bought_is_flagged_and_left_out_of_the_subtotal(): void
    {
        $token = $this->tokenFor($this->makeCustomer(), ['user']);
        $kept = $this->makeProduct(['price' => 10]);
        $gone = $this->makeProduct(['price' => 30]);
        $this->cartCall('POST', '/cart/items', $token, null, ['product_id' => (string) $kept->id, 'qty' => 1]);
        $this->cartCall('POST', '/cart/items', $token, null, ['product_id' => (string) $gone->id, 'qty' => 1]);
        // Not on sale yet and the shop takes no pre-orders.
        $this->setShopConfig(['product_preorder' => '0']);
        ShopProduct::where('id', $gone->id)->update(['date_available' => now()->addDays(3)]);

        $response = $this->cartCall('GET', '/cart', $token)->assertOk();
        $byProduct = collect($this->linesOf($response))->keyBy('product_id');

        $this->assertFalse($byProduct[(string) $gone->id]['purchasable']);
        $this->assertSame('not_for_sale', $byProduct[(string) $gone->id]['reason']);
        $this->assertTrue($byProduct[(string) $kept->id]['purchasable']);
        $this->assertEquals(10, $response->json('data.stores.0.subtotal'));
    }

    public function test_the_cart_answer_carries_no_internal_product_fields(): void
    {
        $response = $this->cartCall('POST', '/cart/items', null, null, ['product_id' => (string) $this->makeProduct()->id, 'qty' => 1]);

        $line = $this->linesOf($response)[0];
        foreach (['cost', 'stock', 'sold', 'status', 'approve'] as $field) {
            $this->assertArrayNotHasKey($field, $line);
        }
    }

    public function test_too_many_cart_calls_in_a_minute_are_throttled(): void
    {
        config(['gp247-config.api.throttle.cart' => 2]);
        $token = $this->tokenFor($this->makeCustomer(), ['user']);

        $this->cartCall('GET', '/cart', $token)->assertOk();
        $this->cartCall('GET', '/cart', $token)->assertOk();
        $this->cartCall('GET', '/cart', $token)->assertStatus(429);
    }

    public function test_reading_the_cart_does_not_query_the_database_once_per_line(): void
    {
        $token = $this->tokenFor($this->makeCustomer(), ['user']);
        $this->cartCall('POST', '/cart/items', $token, null, ['product_id' => (string) $this->makeProduct()->id, 'qty' => 1]);
        $one = $this->queriesOf(fn () => $this->cartCall('GET', '/cart', $token)->assertOk());
        foreach ([1, 2, 3] as $i) {
            $this->cartCall('POST', '/cart/items', $token, null, ['product_id' => (string) $this->makeProduct()->id, 'qty' => 1]);
        }

        $four = $this->queriesOf(fn () => $this->cartCall('GET', '/cart', $token)->assertOk());

        $this->assertSame($one, $four, 'reading 4 lines runs the same queries as reading 1');
    }

    public function test_a_cart_call_through_the_api_leaves_the_website_session_untouched(): void
    {
        session(['storeCheckout' => 'zz-web-store', 'cart.default' => 'zz-web-cart']);

        $this->cartCall('POST', '/cart/items', null, null, ['product_id' => (string) $this->makeProduct()->id, 'qty' => 1])->assertStatus(201);

        $this->assertSame('zz-web-store', session('storeCheckout'));
        $this->assertSame('zz-web-cart', session('cart.default'));
    }

    /**
     * Number of DB queries a call runs.
     *
     * @param callable $call
     * @return int
     */
    private function queriesOf(callable $call): int
    {
        $db = \DB::connection(GP247_DB_CONNECTION);
        $db->flushQueryLog();
        $db->enableQueryLog();
        $call();
        $count = count($db->getQueryLog());
        $db->disableQueryLog();

        return $count;
    }
}
