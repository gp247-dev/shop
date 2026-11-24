<?php

namespace GP247\Shop\Tests\Feature\ShopAddress;

use GP247\Shop\Tests\Feature\ShopAddress\Support\ConfiguresAddressFields;
use GP247\Shop\Tests\Feature\ShopAddress\Support\FakeAddressProvider;
use GP247\Shop\Tests\Feature\ShopAddress\Support\FakeAddressRegistry;
use GP247\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Group;

/**
 * Wherever a customer address is written — registration, the profile, the address book
 * (storefront, admin and API share these mappings) — the stored names are the plugin's
 * and the codes travel with them; a value outside the plugin's list is a field error.
 *
 * @aidlc-unit shop-address
 * @aidlc-story US-shop-address-server-normalize
 * @aidlc-story US-shop-address-structured-meta
 * @aidlc-story US-shop-address-free-text-fallback
 */
#[Group('shop-address')]
class AddressPersistenceTest extends TestCase
{
    use DatabaseTransactions;
    use ConfiguresAddressFields;

    private const SWITCHES = ['customer_city' => '1', 'customer_ward' => '1', 'customer_country' => '1'];

    public function test_registration_stores_the_plugin_names_and_the_codes(): void
    {
        $this->withPlugin();

        $map = gp247_customer_data_insert_mapping($this->raw(['city' => 'hn', 'city_code' => '01', 'ward' => 'x', 'ward_code' => '00008']));

        $this->assertSame('Hà Nội', $map['dataInsert']['city']);
        $this->assertSame('Phường Ngọc Hà', $map['dataInsert']['ward']);
        $this->assertSame('01', $map['dataInsert']['address_meta']['components']['province']['code']);
        $this->assertSame('00008', $map['dataInsert']['address_meta']['components']['ward']['code']);
        $this->assertSame([], $this->addressErrors($map));
    }

    public function test_a_ward_outside_the_selected_province_is_a_field_error_and_nothing_is_tagged(): void
    {
        $this->withPlugin();

        $map = gp247_customer_data_insert_mapping($this->raw(['city' => 'Hà Nội', 'city_code' => '01', 'ward' => 'Phường Sài Gòn', 'ward_code' => '26734']));

        $this->assertSame(['ward'], $this->addressErrors($map));
        $this->assertArrayNotHasKey('address_meta', $map['dataInsert']);
    }

    public function test_the_address_book_stores_the_plugin_names_and_the_codes(): void
    {
        $this->withPlugin();

        $map = gp247_customer_address_mapping($this->raw(['city' => 'Hồ Chí Minh', 'ward' => 'Phường Sài Gòn']));

        $this->assertSame('Hồ Chí Minh', $map['dataAddress']['city']);
        $this->assertSame('26734', $map['dataAddress']['address_meta']['components']['ward']['code']);
        $this->assertSame([], $this->addressErrors($map, 'dataAddress'));
    }

    public function test_changing_the_profile_address_replaces_the_codes(): void
    {
        $this->withPlugin();

        $map = gp247_customer_data_edit_mapping($this->raw(['city' => 'Hồ Chí Minh', 'city_code' => '79', 'ward' => '', 'ward_code' => '']));

        $this->assertSame('Hồ Chí Minh', $map['dataUpdate']['city']);
        $this->assertSame(['province'], array_keys($map['dataUpdate']['address_meta']['components']));
    }

    public function test_a_profile_update_that_does_not_send_the_address_leaves_the_codes_alone(): void
    {
        $this->withPlugin();

        $map = gp247_customer_data_edit_mapping(['first_name' => 'An', 'id' => 'ZZ-ADDRESS-PERSIST', 'country' => 'VN']);

        $this->assertArrayNotHasKey('address_meta', $map['dataUpdate']);
    }

    public function test_moving_an_address_to_a_country_no_plugin_serves_drops_the_old_codes(): void
    {
        FakeAddressRegistry::bind(['FakeAddress' => new FakeAddressProvider()], ['FakeAddress' => ['VN']]);
        $this->configureAddress(self::SWITCHES);

        $map = gp247_customer_address_mapping($this->raw(['city' => 'Springfield', 'country' => 'US']));

        $this->assertSame('Springfield', $map['dataAddress']['city']);
        $this->assertArrayHasKey('address_meta', $map['dataAddress']);
        $this->assertNull($map['dataAddress']['address_meta']);
    }

    public function test_an_address_book_entry_is_saved_with_its_codes_and_loses_them_when_it_leaves_the_plugins_country(): void
    {
        FakeAddressRegistry::bind(['FakeAddress' => new FakeAddressProvider()], ['FakeAddress' => ['VN']]);
        $this->configureAddress(self::SWITCHES);
        $customer = \GP247\Shop\Models\ShopCustomer::create(['first_name' => 'An', 'email' => 'an-address-db@example.com', 'password' => 'x']);

        $entry = \GP247\Shop\Models\ShopCustomerAddress::create(['customer_id' => $customer->id]
            + gp247_customer_address_mapping($this->raw(['city' => 'x', 'city_code' => '79', 'ward' => 'Phường Sài Gòn']))['dataAddress']);
        $saved = \GP247\Shop\Models\ShopCustomerAddress::find($entry->id);
        $this->assertSame('Hồ Chí Minh', $saved->city);
        $this->assertSame('26734', $saved->address_meta['components']['ward']['code']);

        $saved->update(gp247_customer_address_mapping($this->raw(['city' => 'Springfield', 'ward' => '', 'country' => 'US']))['dataAddress']);

        $moved = \GP247\Shop\Models\ShopCustomerAddress::find($entry->id);
        $this->assertSame('Springfield', $moved->city);
        $this->assertNull($moved->address_meta);
    }

    public function test_a_shop_without_an_address_plugin_stores_what_was_typed_and_no_codes(): void
    {
        FakeAddressRegistry::bind([]);
        $this->configureAddress(self::SWITCHES);

        $insert = gp247_customer_data_insert_mapping($this->raw(['city' => 'Springfield', 'city_code' => '01']));
        $book = gp247_customer_address_mapping($this->raw(['city' => 'Springfield']));

        $this->assertSame('Springfield', $insert['dataInsert']['city']);
        $this->assertArrayNotHasKey('address_meta', $insert['dataInsert']);
        $this->assertArrayNotHasKey('address_meta', $book['dataAddress']);
        $this->assertIsString($insert['validate']['city']);
    }

    public function test_a_failing_plugin_lets_the_address_through_as_typed(): void
    {
        $provider = new FakeAddressProvider();
        $provider->failing = ['normalize'];
        FakeAddressRegistry::bind(['FakeAddress' => $provider]);
        $this->configureAddress(self::SWITCHES);

        $map = gp247_customer_address_mapping($this->raw(['city' => 'Hà Nội', 'city_code' => '01']));

        $this->assertSame('Hà Nội', $map['dataAddress']['city']);
        $this->assertNull($map['dataAddress']['address_meta']);
        $this->assertSame([], $this->addressErrors($map, 'dataAddress'));
    }

    /**
     * @return void
     */
    private function withPlugin(): void
    {
        FakeAddressRegistry::bind(['FakeAddress' => new FakeAddressProvider()]);
        $this->configureAddress(self::SWITCHES);
    }

    /**
     * @param array<string, string> $address Address fields.
     * @return array<string, string>
     */
    private function raw(array $address): array
    {
        return $address + [
            'first_name' => 'An', 'email' => 'an-address-persist@example.com', 'password' => 'Secret#123',
            'password_confirmation' => 'Secret#123', 'id' => 'ZZ-ADDRESS-PERSIST', 'address1' => '1 Tràng Tiền', 'country' => 'VN',
        ];
    }

    /**
     * Address fields that fail the mapping's own validation for the data it was built from.
     *
     * @param array<string, mixed> $map     Mapping result.
     * @param string               $dataKey Key of its data array.
     * @return array<int, string>
     */
    private function addressErrors(array $map, string $dataKey = 'dataInsert'): array
    {
        $rules = array_intersect_key($map['validate'], array_flip(['city', 'district', 'ward', 'address1', 'country', 'postcode']));
        $errors = Validator::make($map[$dataKey], $rules, $map['messages'])->errors()->keys();
        sort($errors);

        return $errors;
    }
}
