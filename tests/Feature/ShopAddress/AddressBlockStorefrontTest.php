<?php

namespace GP247\Shop\Tests\Feature\ShopAddress;

use GP247\Front\Testing\InteractsWithFrontStore;
use GP247\Shop\Front\Livewire\CheckoutWizard;
use GP247\Shop\Models\ShopCustomer;
use GP247\Shop\Models\ShopCustomerAddress;
use GP247\Shop\Tests\Feature\ShopAddress\Support\ConfiguresAddressFields;
use GP247\Shop\Tests\Feature\ShopAddress\Support\FakeAddressProvider;
use GP247\Shop\Tests\Feature\ShopAddress\Support\FakeAddressRegistry;
use GP247\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;

/**
 * The storefront forms print their address fields through one shared block: plain inputs that
 * work without JavaScript, and — when an address plugin serves the country — the selects and the
 * data the browser needs to turn them into dependent lists.
 *
 * @aidlc-unit shop-address
 * @aidlc-story US-TPL-address-block-contract
 * @aidlc-story US-shop-address-dependent-select
 * @aidlc-story US-LW-checkout-city-district
 */
#[Group('shop-address')]
class AddressBlockStorefrontTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithFrontStore;
    use ConfiguresAddressFields;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFrontStore();
    }

    protected function tearDown(): void
    {
        $this->tearDownFrontStore();
        parent::tearDown();
    }

    public function test_without_a_plugin_the_registration_form_prints_the_switched_on_fields_as_plain_inputs(): void
    {
        FakeAddressRegistry::bind([]);
        $this->configureAddress(['customer_city' => '1', 'customer_ward' => '1', 'customer_country' => '1']);

        $html = $this->get(gp247_route_front('customer.register'))->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="register-address-block"', $html);
        foreach (['city', 'ward', 'address1', 'country'] as $field) {
            $this->assertMatchesRegularExpression('/name="' . $field . '"/', $html, $field);
        }
        $this->assertStringNotContainsString('name="district"', $html);
        $this->assertStringNotContainsString('name="address2"', $html);
        $this->assertStringContainsString('window.gp247Address', $html);
    }

    public function test_with_a_plugin_the_address_book_prints_selects_and_the_saved_codes(): void
    {
        FakeAddressRegistry::bind(['FakeAddress' => new FakeAddressProvider()]);
        $this->configureAddress(['customer_city' => '1', 'customer_ward' => '1', 'customer_country' => '1']);
        $customer = ShopCustomer::create(['first_name' => 'An', 'email' => 'an-block@example.com', 'password' => bcrypt('Secret#123'), 'status' => 1]);
        $address = ShopCustomerAddress::create([
            'customer_id' => $customer->id, 'first_name' => 'An', 'address1' => '1 Tràng Tiền', 'country' => 'VN',
            'city' => 'Hà Nội', 'ward' => 'Phường Ngọc Hà',
            'address_meta' => ['provider' => 'FakeAddress', 'country' => 'VN', 'data_version' => '2025-07', 'components' => [
                'province' => ['code' => '01', 'name' => 'Hà Nội'], 'ward' => ['code' => '00008', 'name' => 'Phường Ngọc Hà']]],
        ]);
        $this->actingAs($customer, 'customer');

        $html = $this->get(gp247_route_front('customer.update_address', ['id' => $address->id]))->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="account-address-city-select"', $html);
        $this->assertStringContainsString('data-testid="account-address-ward-select"', $html);
        $this->assertMatchesRegularExpression('/name="city_code"[^>]*value="01"|value="01"[^>]*name="city_code"/', $html);
        $this->assertMatchesRegularExpression('/name="ward_code"[^>]*value="00008"|value="00008"[^>]*name="ward_code"/', $html);
        $this->assertStringContainsString('value="Hà Nội"', $html);
    }

    public function test_a_list_field_is_a_searchable_combobox_rather_than_a_plain_select(): void
    {
        FakeAddressRegistry::bind(['FakeAddress' => new FakeAddressProvider()]);
        $this->configureAddress(['customer_city' => '1', 'customer_ward' => '1', 'customer_country' => '1']);
        $customer = ShopCustomer::create(['first_name' => 'An', 'email' => 'an-combo@example.com', 'password' => bcrypt('Secret#123'), 'status' => 1]);
        $address = ShopCustomerAddress::create([
            'customer_id' => $customer->id, 'first_name' => 'An', 'address1' => '1 Tràng Tiền', 'country' => 'VN', 'city' => 'Hà Nội',
        ]);
        $this->actingAs($customer, 'customer');

        $html = $this->get(gp247_route_front('customer.update_address', ['id' => $address->id]))->assertOk()->getContent();

        foreach (['city', 'ward'] as $field) {
            $this->assertMatchesRegularExpression('/<input[^>]*role="combobox"[^>]*data-testid="account-address-' . $field . '-search"/s', $html);
            $this->assertStringContainsString('data-testid="account-address-' . $field . '-option"', $html);
        }
        $this->assertStringContainsString('role="listbox"', $html);
        // The country is searchable too; its native select stays only for browsers without JavaScript.
        $this->assertMatchesRegularExpression('/<input[^>]*role="combobox"[^>]*data-testid="account-address-country-search"/s', $html);
        $this->assertStringContainsString('data-testid="account-address-country-option"', $html);
        $this->assertSame(1, substr_count($html, '<select'));
        $this->assertMatchesRegularExpression('/<select[^>]*name="country"/', $html);
        $this->assertStringContainsString(gp247_language_render('customer.address_search'), $html);
        $this->assertStringContainsString(gp247_language_render('customer.address_no_match'), $html);
        $this->assertStringNotContainsString('customer.address_no_match', $html);
    }

    public function test_the_profile_form_uses_the_shared_block(): void
    {
        FakeAddressRegistry::bind([]);
        $this->configureAddress(['customer_city' => '1']);
        $customer = ShopCustomer::create(['first_name' => 'An', 'email' => 'an-profile-block@example.com', 'password' => bcrypt('Secret#123'),
            'status' => 1, 'city' => 'Đà Nẵng', 'address1' => '2 Bạch Đằng']);
        $this->actingAs($customer, 'customer');

        $html = $this->get(gp247_route_front('customer.change_infomation'))->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="account-profile-block"', $html);
        $this->assertStringContainsString('value="Đà Nẵng"', $html);
    }

    public function test_checkout_binds_the_shared_block_to_the_wizard(): void
    {
        FakeAddressRegistry::bind(['FakeAddress' => new FakeAddressProvider()]);
        $this->configureAddress(['customer_city' => '1', 'customer_ward' => '1', 'customer_country' => '1']);

        $html = Livewire::test(CheckoutWizard::class)->set('country', 'VN')->html();

        $this->assertStringContainsString('data-testid="checkout-address-block"', $html);
        $this->assertStringContainsString('data-testid="checkout-address-ward"', $html);
        $this->assertStringContainsString('data-testid="checkout-address-city-select"', $html);
        // The Alpine config is bound to the wizard: values through $wire, codes in addressCodes.
        $this->assertMatchesRegularExpression('/codesPath(\\\\u0022|&quot;|")\s*:\s*(\\\\u0022|&quot;|")addressCodes/', $html);
        $this->assertStringContainsString('wire:ignore', $html);
        $this->assertStringNotContainsString('name="city_code"', $html);
    }
}
