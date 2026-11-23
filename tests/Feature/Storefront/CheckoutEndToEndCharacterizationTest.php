<?php

namespace GP247\Shop\Tests\Feature\Storefront;

use GP247\Shop\Front\Livewire\CheckoutWizard;
use GP247\Shop\Models\ShopCustomer;
use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopProduct;
use GP247\Shop\Services\CartService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Front\Testing\InteractsWithFrontStore;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\ConfiguresQtyDecimal;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * Characterization of the whole web checkout as it behaves before the CheckoutService
 * extraction (refactor Pha 2 bước A): cart.add → checkout.prepare → GET checkout →
 * CheckoutWizard (address → confirm) → order.add → completeOrder → order.success, on a
 * store with shipping and payment steps turned off. The refactor must keep every
 * assertion here green without editing this file.
 *
 * @aidlc-unit storefront
 * @aidlc-story US-LW-006
 * @aidlc-story US-LW-checkout-idempotency
 */
class CheckoutEndToEndCharacterizationTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithFrontStore;
    use MakesShopProducts;
    use ConfiguresQtyDecimal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFrontStore();
        $this->resetQtyDecimalCache();
        $this->setQtyDecimalEnabled(false);
        $off = ['use_shipping', 'use_payment', 'customer_lastname', 'customer_address2', 'customer_address3', 'customer_phone',
            'customer_country', 'customer_postcode', 'customer_company', 'customer_city', 'customer_district', 'customer_ward',
            'customer_name_kana', 'customer_sex', 'customer_birthday', 'customer_group'];
        $this->setConfig(array_fill_keys($off, '0') + ['shop_allow_guest' => '1']);
    }

    protected function tearDown(): void
    {
        $this->tearDownFrontStore();
        parent::tearDown();
    }

    /**
     * Set admin_config values (every store row of each key) inside the test transaction.
     *
     * @param array<string, string> $values
     * @return void
     */
    private function setConfig(array $values): void
    {
        $table = \DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config');
        foreach ($values as $key => $value) {
            $table->clone()->where('key', $key)->update(['value' => $value]);
        }
        foreach (['getAllGlobal' => null, 'getAllConfigOfStore' => []] as $name => $empty) {
            $prop = new \ReflectionProperty(\GP247\Core\Models\AdminConfig::class, $name);
            $prop->setValue(null, $empty);
        }
    }

    /**
     * Run the storefront checkout up to the confirm step; returns the cart row id.
     *
     * @param ShopProduct $product
     * @param string      $email
     * @return string
     */
    private function reachConfirm(ShopProduct $product, string $email): string
    {
        $this->post(route('cart.add'), ['product_id' => (string) $product->id, 'qty' => 2])->assertStatus(302);
        $rowId = (string) (new CartService())->instance('default')->content()->first()->rowId;

        $this->post(route('checkout.prepare'), ['store_id' => (string) $this->storeId(), "qty-{$rowId}" => 2])
            ->assertRedirect(route('checkout'));
        $this->get(route('checkout'))->assertOk();
        $this->assertCount(1, session('dataCheckout'), 'GET checkout stages the store lines as dataCheckout');

        Livewire::test(CheckoutWizard::class)
            ->set('first_name', 'ZZ Buyer')
            ->set('email', $email)
            ->set('address1', '1 ZZ street')
            ->call('nextStep')
            ->assertSet('step', 'confirm');

        $this->assertCount(1, session('dataCheckout') ?? [], 'dataCheckout survives the wizard');
        $this->assertNotEmpty(session('checkoutToken'), 'the wizard stamps the idempotency token');
        $this->assertSame('ZZ Buyer', session('shippingAddress')['first_name']);
        $this->assertTrue(session()->has('dataTotal'));

        return $rowId;
    }

    public function test_a_signed_in_customer_places_an_order_through_the_whole_web_checkout(): void
    {
        $product = $this->makeProduct('ZZ Char Product', ['price' => 25, 'stock' => 10, 'tax_id' => 0]);
        $customer = ShopCustomer::create([
            'first_name' => 'ZZ', 'email' => 'zz-char-' . uniqid() . '@example.com', 'password' => bcrypt('Secret#123'),
            'status' => 1, 'store_id' => (string) $this->storeId(),
        ]);
        $this->actingAs($customer, 'customer');
        $email = 'zz-char-order-' . uniqid() . '@example.com';

        $rowId = $this->reachConfirm($product, $email);
        $token = session('checkoutToken');

        $response = $this->post(route('order.add'), ['_token' => csrf_token()]);

        $this->assertSame('', (string) session('error'), 'order.add flashed an error: ' . session('error'));
        $order = ShopOrder::where('checkout_token', $token)->first();
        $this->assertNotNull($order, 'order.add creates exactly the order of this checkout');
        $response->assertRedirect(route('order.success'));
        $response->assertSessionHas('orderID', $order->id);
        $this->assertSame((string) $customer->id, (string) $order->customer_id);
        $this->assertSame((string) $this->storeId(), (string) $order->store_id);
        $this->assertSame($email, $order->email);
        $this->assertSame('ZZ Buyer', $order->first_name);
        $this->assertSame('1 ZZ street', $order->address1);
        $this->assertEquals(50, (float) $order->subtotal);
        $this->assertEquals(50, (float) $order->total);
        $this->assertCount(1, $order->details);
        $this->assertEquals(2, (float) $order->details->first()->qty);
        $this->assertEquals(25, (float) $order->details->first()->price);
        $this->assertEquals(8, (int) ShopProduct::find($product->id)->stock, 'stock is taken when the order is created');
        $this->assertNull((new CartService())->instance('default')->get($rowId), 'the ordered line leaves the cart');
        foreach (['dataCheckout', 'dataTotal', 'checkoutToken', 'paymentMethod', 'shippingMethod', 'storeCheckout'] as $key) {
            $this->assertFalse(session()->has($key) && session($key) !== null && session($key) !== [], "completeOrder clears {$key}");
        }

        $this->get(route('order.success'))->assertOk()->assertSee($order->id);
    }

    public function test_a_guest_places_an_order_through_the_whole_web_checkout(): void
    {
        $product = $this->makeProduct('ZZ Char Guest', ['price' => 12.5, 'stock' => 5, 'tax_id' => 0]);
        $email = 'zz-char-guest-' . uniqid() . '@example.com';

        $this->reachConfirm($product, $email);
        $token = session('checkoutToken');
        $this->post(route('order.add'), ['_token' => csrf_token()])->assertRedirect(route('order.success'));

        $order = ShopOrder::where('checkout_token', $token)->first();
        $this->assertNotNull($order);
        $this->assertEmpty($order->customer_id);
        $this->assertEquals(25, (float) $order->total);
        $this->assertEquals(3, (int) ShopProduct::find($product->id)->stock);
    }

    public function test_posting_order_add_again_after_completion_creates_no_second_order(): void
    {
        $product = $this->makeProduct('ZZ Char Replay', ['price' => 10, 'stock' => 10, 'tax_id' => 0]);
        $email = 'zz-char-replay-' . uniqid() . '@example.com';
        $this->reachConfirm($product, $email);
        $this->post(route('order.add'), ['_token' => csrf_token()])->assertRedirect(route('order.success'));
        $orders = ShopOrder::where('email', $email)->count();

        $again = $this->post(route('order.add'), ['_token' => csrf_token()]);

        // Today: completeOrder() cleared dataCheckout, so the first guard sends the buyer home.
        $again->assertRedirect(gp247_route_front('front.home'));
        $this->assertSame($orders, ShopOrder::where('email', $email)->count());
        $this->assertEquals(8, (int) ShopProduct::find($product->id)->stock);
    }
}
