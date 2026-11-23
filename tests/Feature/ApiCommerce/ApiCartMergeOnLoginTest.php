<?php

namespace GP247\Shop\Tests\Feature\ApiCommerce;

use GP247\Shop\Models\ShopCart;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Group;
use GP247\Shop\Tests\Feature\ApiCommerce\Support\ApiCommerceFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * A shopper who fills a cart as a guest and then signs in (or registers) in the app keeps
 * what they chose: the guest cart joins the account cart by the website's rule, the old
 * cart token stops working, and the line cap still holds.
 *
 * @aidlc-unit api-commerce
 * @aidlc-story US-api-commerce-cart-merge-on-login
 */
#[Group('api-commerce')]
class ApiCartMergeOnLoginTest extends TestCase
{
    use DatabaseTransactions;
    use ApiCommerceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useSellableShop();
        $this->useMinimalCustomerForm();
    }

    /**
     * Fill a guest cart; returns the cart token.
     *
     * @param array<string, int> $lines product id => qty
     * @return string
     */
    private function guestCart(array $lines): string
    {
        $cartToken = null;
        foreach ($lines as $productId => $qty) {
            $response = $this->cartCall('POST', '/cart/items', null, $cartToken, ['product_id' => (string) $productId, 'qty' => $qty])->assertStatus(201);
            $cartToken = $cartToken ?? (string) $response->headers->get('X-Cart-Token');
        }

        return $cartToken;
    }

    public function test_signing_in_with_a_guest_cart_adds_its_lines_to_the_account_cart(): void
    {
        $customer = $this->makeCustomer();
        $shared = $this->makeProduct();
        $guestOnly = $this->makeProduct();
        $this->cartCall('POST', '/cart/items', $this->tokenFor($customer, ['user']), null, ['product_id' => (string) $shared->id, 'qty' => 1]);
        $cartToken = $this->guestCart([(string) $shared->id => 2, (string) $guestOnly->id => 1]);

        $this->flushHeaders();
        $login = $this->withHeader('X-Cart-Token', $cartToken)
            ->postJson($this->v2Front() . '/login', ['email' => $customer->email, 'password' => 'Secret#123'])->assertOk();

        $this->assertSame(2, $login->json('data.cart_merge.merged'));
        $this->assertSame(0, $login->json('data.cart_merge.dropped'));
        $qty = collect($this->linesOf($this->cartCall('GET', '/cart', $login->json('data.access_token'))))->pluck('qty', 'product_id');
        $this->assertEquals(3, $qty[(string) $shared->id], 'same product + variant adds up');
        $this->assertEquals(1, $qty[(string) $guestOnly->id]);
        $this->assertSame(0, ShopCart::where('identifier', 'guest:' . hash('sha256', $cartToken))->count(), 'the guest cart is gone');
        $this->assertSame([], $this->cartCall('GET', '/cart', null, $cartToken)->json('data.stores'), 'the old cart token opens nothing');
    }

    public function test_registering_with_a_guest_cart_keeps_the_lines(): void
    {
        $product = $this->makeProduct();
        $cartToken = $this->guestCart([(string) $product->id => 2]);

        $this->flushHeaders();
        $register = $this->withHeader('X-Cart-Token', $cartToken)->postJson($this->v2Front() . '/register', [
            'first_name' => 'ZZ', 'email' => 'zz-merge-' . uniqid() . '@example.com', 'address1' => '1 ZZ street', 'password' => 'Secret#123', 'password_confirmation' => 'Secret#123',
        ])->assertStatus(201);

        $this->assertSame(1, $register->json('data.cart_merge.merged'));
        $lines = $this->linesOf($this->cartCall('GET', '/cart', $register->json('data.access_token')));
        $this->assertEquals(2, $lines[0]['qty']);
    }

    public function test_a_merge_beyond_the_line_limit_keeps_the_limit_and_reports_what_was_left_out(): void
    {
        config(['gp247-config.shop.api_cart_max_lines' => 2]);
        $customer = $this->makeCustomer();
        $this->cartCall('POST', '/cart/items', $this->tokenFor($customer, ['user']), null, ['product_id' => (string) $this->makeProduct()->id, 'qty' => 1]);
        $cartToken = $this->guestCart([(string) $this->makeProduct()->id => 1, (string) $this->makeProduct()->id => 1]);

        $this->flushHeaders();
        $login = $this->withHeader('X-Cart-Token', $cartToken)
            ->postJson($this->v2Front() . '/login', ['email' => $customer->email, 'password' => 'Secret#123'])->assertOk();

        $this->assertSame(1, $login->json('data.cart_merge.dropped'));
        $this->assertSame(2, $this->cartCall('GET', '/cart', $login->json('data.access_token'))->json('data.line_count'));
    }

    public function test_signing_in_without_a_cart_token_changes_nothing_in_the_answer(): void
    {
        $customer = $this->makeCustomer();

        $this->flushHeaders();
        $login = $this->postJson($this->v2Front() . '/login', ['email' => $customer->email, 'password' => 'Secret#123'])->assertOk();

        $this->assertArrayNotHasKey('cart_merge', $login->json('data'));
        $this->assertNotEmpty($login->json('data.access_token'));
    }
}
