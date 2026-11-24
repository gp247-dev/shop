<?php

namespace GP247\Shop\Tests\Feature\ShopAddress;

use GP247\Shop\Address\AddressForm;
use GP247\Shop\Address\AddressService;
use GP247\Shop\Tests\Feature\ShopAddress\Support\ConfiguresAddressFields;
use GP247\Shop\Tests\Feature\ShopAddress\Support\FakeAddressProvider;
use GP247\Shop\Tests\Feature\ShopAddress\Support\FakeAddressRegistry;
use GP247\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Group;

/**
 * The server decides what an address is: codes are checked against the plugin's data,
 * the stored name is the plugin's own, the codes are kept next to it — and a plugin
 * that fails never stops an address from being saved.
 *
 * @aidlc-unit shop-address
 * @aidlc-story US-shop-address-server-normalize
 * @aidlc-story US-shop-address-structured-meta
 * @aidlc-story US-shop-address-dependent-select
 * @aidlc-story US-shop-address-postcode-lookup
 * @aidlc-story US-shop-address-free-text-fallback
 */
#[Group('shop-address')]
class AddressServiceTest extends TestCase
{
    use DatabaseTransactions;
    use ConfiguresAddressFields;

    private FakeAddressProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new FakeAddressProvider();
        FakeAddressRegistry::bind(['FakeAddress' => $this->provider]);
        $this->configureAddress(['customer_city' => '1', 'customer_district' => '1', 'customer_ward' => '1',
            'customer_postcode' => '1', 'customer_country' => '1']);
    }

    public function test_a_child_list_only_holds_the_choices_of_the_selected_parent(): void
    {
        $result = AddressService::make()->options(null, 'VN', 'ward', '01');

        $this->assertFalse($result['fallback']);
        $this->assertSame(['00004', '00008'], array_column($result['options'], 'code'));
        $this->assertSame('Phường Ba Đình', $result['options'][0]['name']);
    }

    public function test_the_same_list_is_asked_from_the_plugin_only_once(): void
    {
        AddressService::make()->options(null, 'VN', 'province', null);
        AddressService::make()->options(null, 'VN', 'province', null);

        $this->assertSame(1, $this->provider->calls['options']);
    }

    public function test_an_empty_list_is_not_remembered(): void
    {
        AddressService::make()->options(null, 'VN', 'ward', 'no-such-province');
        AddressService::make()->options(null, 'VN', 'ward', 'no-such-province');

        $this->assertSame(2, $this->provider->calls['options']);
    }

    public function test_something_that_cannot_be_a_code_is_dropped_and_the_name_decides(): void
    {
        $resolution = AddressService::make()->normalize(null, 'VN', ['city' => 'Hà Nội', 'city_code' => "01' OR '1'='1"], AddressForm::CONTEXT_CHECKOUT);

        $this->assertTrue($resolution->passes());
        $this->assertSame('01', $resolution->meta['components']['province']['code']);
    }

    public function test_a_value_is_only_refused_where_the_shopper_can_pick_from_a_list(): void
    {
        config(['gp247-config.shop.address_providers.FakeAddress.capabilities' => []]);

        $resolution = AddressService::make()->normalize(null, 'VN', ['city' => 'Atlantis'], AddressForm::CONTEXT_CHECKOUT);

        $this->assertTrue($resolution->passes());
        $this->assertNull($resolution->meta);
    }

    public function test_a_component_the_plugin_does_not_define_returns_no_choice(): void
    {
        $result = AddressService::make()->options(null, 'VN', 'galaxy', null);

        $this->assertSame([], $result['options']);
        $this->assertArrayNotHasKey('options', $this->provider->calls);
    }

    public function test_a_failing_list_tells_the_form_to_fall_back_to_typing(): void
    {
        $this->provider->failing = ['options'];

        $result = AddressService::make()->options(null, 'VN', 'province', null);

        $this->assertTrue($result['fallback']);
        $this->assertSame([], $result['options']);
    }

    public function test_a_postcode_returns_every_matching_address(): void
    {
        $one = AddressService::make()->lookup(null, 'JP', '100-0001');
        $two = AddressService::make()->lookup(null, 'JP', '530-0001');
        $none = AddressService::make()->lookup(null, 'JP', '999-9999');

        $this->assertCount(1, $one['suggestions']);
        $this->assertSame('東京都', $one['suggestions'][0]['components']['prefecture']['name']);
        $this->assertCount(2, $two['suggestions']);
        $this->assertSame([], $none['suggestions']);
        $this->assertFalse($none['fallback']);
    }

    public function test_a_failing_postcode_lookup_does_not_break_the_form(): void
    {
        $this->provider->failing = ['lookup'];

        $result = AddressService::make()->lookup(null, 'JP', '100-0001');

        $this->assertTrue($result['fallback']);
        $this->assertSame([], $result['suggestions']);
    }

    public function test_submitted_codes_are_stored_with_the_name_of_the_plugin_not_the_one_the_browser_sent(): void
    {
        $resolution = AddressService::make()->normalize(null, 'VN', [
            'city' => '<b>Ha Noi</b>', 'city_code' => '01', 'ward' => 'anything', 'ward_code' => '00008', 'address1' => '1 Tràng Tiền',
        ], AddressForm::CONTEXT_CHECKOUT);

        $this->assertTrue($resolution->passes());
        $this->assertSame(['city' => 'Hà Nội', 'ward' => 'Phường Ngọc Hà'], $resolution->values);
        $this->assertSame('FakeAddress', $resolution->meta['provider']);
        $this->assertSame('VN', $resolution->meta['country']);
        $this->assertSame('2025-07', $resolution->meta['data_version']);
        $this->assertSame(['code' => '01', 'name' => 'Hà Nội'], $resolution->meta['components']['province']);
        $this->assertSame(['code' => '00008', 'name' => 'Phường Ngọc Hà'], $resolution->meta['components']['ward']);
    }

    public function test_a_name_typed_by_hand_is_matched_to_its_code(): void
    {
        $resolution = AddressService::make()->normalize(null, 'VN', ['city' => 'Hồ Chí Minh', 'ward' => 'Phường Sài Gòn'], AddressForm::CONTEXT_CHECKOUT);

        $this->assertTrue($resolution->passes());
        $this->assertSame('79', $resolution->meta['components']['province']['code']);
        $this->assertSame('26734', $resolution->meta['components']['ward']['code']);
    }

    public function test_a_ward_of_another_province_is_refused(): void
    {
        $resolution = AddressService::make()->normalize(null, 'VN', [
            'city' => 'Hà Nội', 'city_code' => '01', 'ward' => 'Phường Sài Gòn', 'ward_code' => '26734',
        ], AddressForm::CONTEXT_CHECKOUT);

        $this->assertFalse($resolution->passes());
        $this->assertArrayHasKey('ward', $resolution->errors);
        $this->assertArrayNotHasKey('city', $resolution->errors);
    }

    public function test_a_code_that_does_not_exist_is_refused(): void
    {
        $resolution = AddressService::make()->normalize(null, 'VN', ['city' => 'Atlantis', 'city_code' => '999'], AddressForm::CONTEXT_CHECKOUT);

        $this->assertArrayHasKey('city', $resolution->errors);
    }

    public function test_when_matching_is_not_enforced_an_unknown_value_is_kept_as_typed_without_a_code(): void
    {
        $this->configureAddress(['customer_city' => '1', 'customer_ward' => '1', 'customer_country' => '1',
            'address_mapping_VN' => json_encode(['provider' => 'FakeAddress', 'strict' => false,
                'fields' => ['city' => ['component' => 'province'], 'ward' => ['component' => 'ward']]])]);

        $resolution = AddressService::make()->normalize(null, 'VN', ['city' => 'Hà Nội', 'ward' => 'Xã Không Tên'], AddressForm::CONTEXT_CHECKOUT);

        $this->assertTrue($resolution->passes());
        $this->assertSame(['city' => 'Hà Nội'], $resolution->values);
        $this->assertSame(['province'], array_keys($resolution->meta['components']));
    }

    public function test_a_field_left_empty_is_not_sent_to_the_plugin_and_carries_no_code(): void
    {
        $resolution = AddressService::make()->normalize(null, 'VN', ['city' => '', 'ward' => '', 'address1' => '1 Tràng Tiền'], AddressForm::CONTEXT_CHECKOUT);

        $this->assertTrue($resolution->passes());
        $this->assertNull($resolution->meta);
        $this->assertArrayNotHasKey('normalize', $this->provider->calls);
    }

    public function test_a_plugin_that_fails_while_checking_lets_the_address_through_as_free_text(): void
    {
        $this->provider->failing = ['normalize'];

        $resolution = AddressService::make()->normalize(null, 'VN', ['city' => 'Hà Nội', 'city_code' => '01'], AddressForm::CONTEXT_CHECKOUT);

        $this->assertTrue($resolution->passes());
        $this->assertSame([], $resolution->values);
        $this->assertNull($resolution->meta);
    }

    public function test_a_country_without_a_plugin_is_stored_as_typed(): void
    {
        $resolution = AddressService::make()->normalize(null, 'US', ['city' => 'Springfield', 'city_code' => '01'], AddressForm::CONTEXT_CHECKOUT);

        $this->assertTrue($resolution->passes());
        $this->assertNull($resolution->meta);
        $this->assertSame(['city' => 'Springfield', 'address_meta' => null], $resolution->applyTo(['city' => 'Springfield']));
    }

    public function test_a_prefilled_field_the_shopper_may_edit_keeps_what_they_typed(): void
    {
        $this->configureAddress(['customer_city' => '1', 'customer_district' => '1', 'customer_postcode' => '1', 'customer_country' => '1']);

        $resolution = AddressService::make()->normalize(null, 'JP', [
            'postcode' => '100-0001', 'city' => '東京都', 'city_code' => '13',
            'district' => '千代田区', 'district_code' => '13101', 'address1' => '千代田1-1', 'address1_code' => '0001',
        ], AddressForm::CONTEXT_CHECKOUT);

        $this->assertTrue($resolution->passes());
        $this->assertArrayNotHasKey('address1', $resolution->values);
        $this->assertSame('東京都', $resolution->values['city']);
        $this->assertSame('13101', $resolution->meta['components']['municipality']['code']);
        $this->assertSame('100-0001', $resolution->meta['components']['postcode']['code']);
    }
}
