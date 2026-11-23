<?php

namespace GP247\Shop\Tests\Feature\ApiCommerce;

use GP247\Shop\Models\ShopCart;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Group;
use GP247\Shop\Tests\Feature\ApiCommerce\Support\ApiCommerceFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * A shopper who is not signed in keeps a cart in the app with a cart token: given once
 * when the first line is added, only where the shop sells to guests, unguessable, stored
 * hashed, and gone after 30 days without a change.
 *
 * @aidlc-unit api-commerce
 * @aidlc-story US-api-commerce-guest-cart-token
 */
#[Group('api-commerce')]
class ApiGuestCartTokenTest extends TestCase
{
    use DatabaseTransactions;
    use ApiCommerceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useSellableShop();
    }

    public function test_a_shop_that_does_not_sell_to_guests_asks_the_app_to_sign_in_and_gives_no_cart_token(): void
    {
        $this->setShopConfig(['shop_allow_guest' => '0']);

        $response = $this->cartCall('POST', '/cart/items', null, null, ['product_id' => (string) $this->makeProduct()->id, 'qty' => 1])
            ->assertStatus(401)->assertJson(['code' => 'login_required']);

        $this->assertFalse($response->headers->has('X-Cart-Token'));
    }

    public function test_the_first_line_added_by_a_guest_gives_a_cart_token_that_opens_the_same_cart(): void
    {
        $product = $this->makeProduct();

        $first = $this->cartCall('POST', '/cart/items', null, null, ['product_id' => (string) $product->id, 'qty' => 2])->assertStatus(201);
        $cartToken = (string) $first->headers->get('X-Cart-Token');

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $cartToken, '256-bit token, base64url');
        $lines = $this->linesOf($this->cartCall('GET', '/cart', null, $cartToken)->assertOk());
        $this->assertSame((string) $product->id, (string) $lines[0]['product_id']);
        $this->assertEquals(2, $lines[0]['qty']);
    }

    public function test_the_cart_token_is_stored_hashed_and_given_only_once(): void
    {
        $first = $this->cartCall('POST', '/cart/items', null, null, ['product_id' => (string) $this->makeProduct()->id, 'qty' => 1]);
        $cartToken = (string) $first->headers->get('X-Cart-Token');

        $row = ShopCart::where('identifier', 'guest:' . hash('sha256', $cartToken))->first();
        $this->assertNotNull($row, 'the cart is kept under the hash of the token');
        $this->assertSame(0, ShopCart::where('identifier', 'like', '%' . $cartToken . '%')->count(), 'the raw token is never stored');
        $this->assertStringNotContainsString($cartToken, (string) $first->getContent(), 'the token is not in the body');

        $second = $this->cartCall('POST', '/cart/items', null, $cartToken, ['product_id' => (string) $this->makeProduct()->id, 'qty' => 1]);
        $this->assertFalse($second->headers->has('X-Cart-Token'), 'a known cart gets no new token');
    }

    public function test_a_wrong_cart_token_opens_no_cart_and_adding_starts_a_new_one(): void
    {
        $this->cartCall('POST', '/cart/items', null, null, ['product_id' => (string) $this->makeProduct()->id, 'qty' => 1]);
        $wrong = str_repeat('A', 43);

        $this->assertSame([], $this->cartCall('GET', '/cart', null, $wrong)->assertOk()->json('data.stores'));
        $added = $this->cartCall('POST', '/cart/items', null, $wrong, ['product_id' => (string) $this->makeProduct()->id, 'qty' => 1]);
        $this->assertNotSame($wrong, (string) $added->headers->get('X-Cart-Token'));
        $this->assertCount(1, $this->linesOf($added));
    }

    public function test_a_guest_cart_untouched_for_more_than_30_days_is_gone(): void
    {
        $cartToken = (string) $this->cartCall('POST', '/cart/items', null, null, ['product_id' => (string) $this->makeProduct()->id, 'qty' => 1])
            ->headers->get('X-Cart-Token');
        $identifier = 'guest:' . hash('sha256', $cartToken);
        ShopCart::where('identifier', $identifier)->update(['updated_at' => now()->subDays(31)]);

        $this->assertSame([], $this->cartCall('GET', '/cart', null, $cartToken)->assertOk()->json('data.stores'));
        $this->assertSame(0, ShopCart::where('identifier', $identifier)->count(), 'the expired cart is cleaned up on access');
    }
}
