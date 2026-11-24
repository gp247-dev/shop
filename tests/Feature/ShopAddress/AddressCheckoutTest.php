<?php

namespace GP247\Shop\Tests\Feature\ShopAddress;

use GP247\Core\Models\AdminConfig;
use GP247\Front\Testing\InteractsWithFrontStore;
use GP247\Shop\Front\Livewire\CheckoutWizard;
use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Services\CartService;
use GP247\Shop\Tests\Feature\ShopAddress\Support\FakeAddressProvider;
use GP247\Shop\Tests\Feature\ShopAddress\Support\FakeAddressRegistry;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\ConfiguresQtyDecimal;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;

/**
 * At checkout the address a shopper picks from the plugin's lists is checked on the
 * server, and the order keeps both the names and the codes of that address.
 *
 * @aidlc-unit shop-address
 * @aidlc-story US-shop-address-server-normalize
 * @aidlc-story US-shop-address-structured-meta
 * @aidlc-story US-LW-checkout-city-district
 */
#[Group('shop-address')]
class AddressCheckoutTest extends TestCase
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
            'customer_postcode', 'customer_company', 'customer_district', 'customer_name_kana', 'customer_sex',
            'customer_birthday', 'customer_group', 'customer_city_required', 'customer_ward_required', 'customer_country_required'];
        $this->setConfig(array_fill_keys($off, '0') + ['shop_allow_guest' => '1', 'customer_city' => '1', 'customer_ward' => '1', 'customer_country' => '1']);
        FakeAddressRegistry::bind(['FakeAddress' => new FakeAddressProvider()]);
    }

    protected function tearDown(): void
    {
        $this->tearDownFrontStore();
        AdminConfig::forgetMemo();
        parent::tearDown();
    }

    public function test_the_order_keeps_the_names_and_the_codes_of_the_address_picked_at_checkout(): void
    {
        $email = 'zz-address-checkout-' . uniqid() . '@example.com';
        $this->stageCheckout();

        Livewire::test(CheckoutWizard::class)
            ->set('first_name', 'ZZ Buyer')->set('email', $email)->set('address1', '1 Tràng Tiền')
            ->set('country', 'VN')
            ->set('city', 'typed by the browser')->set('addressCodes.city', '01')
            ->set('ward', 'typed by the browser')->set('addressCodes.ward', '00004')
            ->call('nextStep')
            ->assertHasNoErrors()
            ->assertSet('step', 'confirm')
            ->assertSet('city', 'Hà Nội')
            ->assertSet('ward', 'Phường Ba Đình');

        $this->assertSame('Hà Nội', session('shippingAddress')['city']);
        $this->assertSame('01', session('shippingAddress')['address_meta']['components']['province']['code']);

        $this->post(route('order.add'), ['_token' => csrf_token()])->assertRedirect(route('order.success'));

        $order = ShopOrder::where('email', $email)->first();
        $this->assertNotNull($order);
        $this->assertSame('Hà Nội', $order->city);
        $this->assertSame('Phường Ba Đình', $order->ward);
        $this->assertSame('FakeAddress', $order->address_meta['provider']);
        $this->assertSame('00004', $order->address_meta['components']['ward']['code']);
    }

    public function test_checkout_does_not_advance_with_a_ward_outside_the_selected_province(): void
    {
        $this->stageCheckout();

        Livewire::test(CheckoutWizard::class)
            ->set('first_name', 'ZZ Buyer')->set('email', 'zz-address-refused@example.com')->set('address1', '1 Tràng Tiền')
            ->set('country', 'VN')
            ->set('city', 'Hà Nội')->set('addressCodes.city', '01')
            ->set('ward', 'Phường Sài Gòn')->set('addressCodes.ward', '26734')
            ->call('nextStep')
            ->assertHasErrors(['ward'])
            ->assertSet('step', 'address');
    }

    public function test_an_address_changed_after_the_address_step_is_checked_again_before_it_is_committed(): void
    {
        $this->stageCheckout();

        Livewire::test(CheckoutWizard::class)
            ->set('first_name', 'ZZ Buyer')->set('email', 'zz-address-tamper@example.com')->set('address1', '1 Tràng Tiền')
            ->set('country', 'VN')
            ->set('step', 'payment')
            ->set('city', 'Atlantis')
            ->call('nextStep')
            ->assertHasErrors(['city'])
            ->assertSet('step', 'address');

        $this->assertNull(session('shippingAddress'));
    }

    public function test_a_country_without_a_plugin_checks_out_with_typed_values_and_no_codes(): void
    {
        $email = 'zz-address-freetext-' . uniqid() . '@example.com';
        $this->stageCheckout();

        Livewire::test(CheckoutWizard::class)
            ->set('first_name', 'ZZ Buyer')->set('email', $email)->set('address1', '1 Main St')
            ->set('country', 'US')->set('city', 'Springfield')->set('ward', 'Downtown')
            ->call('nextStep')
            ->assertHasNoErrors()
            ->assertSet('step', 'confirm');

        $this->post(route('order.add'), ['_token' => csrf_token()])->assertRedirect(route('order.success'));

        $order = ShopOrder::where('email', $email)->first();
        $this->assertSame('Springfield', $order->city);
        $this->assertNull($order->address_meta);
    }

    /**
     * Puts one product in the cart and opens the checkout page.
     *
     * @return void
     */
    private function stageCheckout(): void
    {
        $product = $this->makeProduct('ZZ Address Product', ['price' => 50, 'stock' => 100, 'tax_id' => 0]);
        $this->post(route('cart.add'), ['product_id' => (string) $product->id, 'qty' => 1])->assertStatus(302);
        $rowId = (string) (new CartService())->instance('default')->content()->first()->rowId;
        $this->post(route('checkout.prepare'), ['store_id' => (string) $this->storeId(), "qty-{$rowId}" => 1])
            ->assertRedirect(route('checkout'));
        $this->get(route('checkout'))->assertOk();
    }

    /**
     * @param array<string, string> $values admin_config values, every store row of each key.
     * @return void
     */
    private function setConfig(array $values): void
    {
        $table = \DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config');
        foreach ($values as $key => $value) {
            $table->clone()->where('key', $key)->update(['value' => $value]);
        }
        AdminConfig::forgetMemo();
    }
}
