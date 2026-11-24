<?php

namespace GP247\Shop\Tests\Feature\Storefront;

use GP247\Core\Models\AdminCountry;
use GP247\Shop\Tests\Feature\ShopAddress\Support\ConfiguresAddressFields;
use GP247\Shop\Tests\Feature\ShopAddress\Support\FakeAddressRegistry;
use GP247\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Group;

/**
 * Registration, the profile form, the address book and checkout only ask for the
 * address fields the shop switched on, and only require the ones marked required.
 *
 * @aidlc-unit storefront
 * @aidlc-story US-LW-checkout-city-district
 */
#[Group('storefront')]
class AddressFieldRulesTest extends TestCase
{
    use DatabaseTransactions;
    use ConfiguresAddressFields;

    private const SWITCHES = [
        'customer_city' => '1', 'customer_city_required' => '1',
        'customer_district' => '1', 'customer_district_required' => '0',
        'customer_address1' => '1', 'customer_address1_required' => '1',
        'customer_address2' => '1', 'customer_address2_required' => '0',
        'customer_address3' => '1', 'customer_address3_required' => '1',
        'customer_country' => '1', 'customer_country_required' => '1',
        'customer_postcode' => '1', 'customer_postcode_required' => '0',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        FakeAddressRegistry::bind([]);
    }

    public function test_registration_asks_for_the_switched_on_fields_and_requires_only_the_required_ones(): void
    {
        $this->configureAddress(self::SWITCHES);

        $map = gp247_customer_data_insert_mapping($this->raw());

        $this->assertSame($this->expectedRules(), $this->addressRules($map['validate']));
        $this->assertSame('Hà Nội', $map['dataInsert']['city']);
        $this->assertSame('1 Tràng Tiền', $map['dataInsert']['address1']);
        $this->assertSame('VN', $map['dataInsert']['country']);
        $this->assertArrayNotHasKey('ward', $map['dataInsert']);
    }

    public function test_the_profile_form_asks_for_the_switched_on_fields_and_requires_only_the_required_ones(): void
    {
        $this->configureAddress(self::SWITCHES);

        $map = gp247_customer_data_edit_mapping($this->raw());

        $this->assertSame($this->expectedRules(), $this->addressRules($map['validate']));
        $this->assertSame('Hà Nội', $map['dataUpdate']['city']);
        $this->assertArrayNotHasKey('ward', $map['dataUpdate']);
    }

    public function test_checkout_asks_for_the_switched_on_fields_and_requires_only_the_required_ones(): void
    {
        $this->configureAddress(self::SWITCHES);

        $this->assertSame($this->expectedRules(), $this->addressRules(gp247_order_mapping_validate()['validate']));
    }

    public function test_a_field_switched_off_is_neither_validated_nor_stored(): void
    {
        $this->configureAddress(['customer_address1_required' => '0']);
        $raw = $this->raw() + ['ward' => 'Phường Ba Đình', 'district' => 'Ba Đình', 'address2' => 'Tầng 2', 'postcode' => '100000'];

        $insert = gp247_customer_data_insert_mapping($raw);
        $edit = gp247_customer_data_edit_mapping($raw);
        $book = gp247_customer_address_mapping($raw);
        $order = gp247_order_mapping_validate();

        foreach (['city', 'district', 'ward', 'address2', 'address3', 'country', 'postcode'] as $field) {
            $this->assertArrayNotHasKey($field, $insert['validate'], 'insert rule ' . $field);
            $this->assertArrayNotHasKey($field, $insert['dataInsert'], 'insert data ' . $field);
            $this->assertArrayNotHasKey($field, $edit['validate'], 'edit rule ' . $field);
            $this->assertArrayNotHasKey($field, $edit['dataUpdate'], 'edit data ' . $field);
            $this->assertArrayNotHasKey($field, $book['validate'], 'book rule ' . $field);
            $this->assertArrayNotHasKey($field, $book['dataAddress'], 'book data ' . $field);
            $this->assertArrayNotHasKey($field, $order['validate'], 'order rule ' . $field);
        }
        $this->assertSame('nullable|string|max:100', $insert['validate']['address1']);
        $this->assertSame('nullable|string|max:100', $order['validate']['address1']);
    }

    public function test_the_address_book_always_requires_the_main_address_line(): void
    {
        $this->configureAddress(['customer_address1_required' => '0']);

        $map = gp247_customer_address_mapping($this->raw());

        $this->assertSame('required|string|max:100', $map['validate']['address1']);
        $this->assertSame('1 Tràng Tiền', $map['dataAddress']['address1']);
    }

    public function test_the_address_book_requires_only_the_fields_marked_required(): void
    {
        $this->configureAddress(self::SWITCHES + ['customer_phone' => '1', 'customer_phone_required' => '0']);

        $map = gp247_customer_address_mapping($this->raw());

        $this->assertSame('required|string|max:100', $map['validate']['city']);
        $this->assertSame('nullable|string|max:100', $map['validate']['district']);
        $this->assertSame('nullable|string|max:100', $map['validate']['address2']);
        $this->assertSame('required|string|max:100', $map['validate']['address3']);
        $this->assertStringStartsWith('nullable|', $map['validate']['phone']);
        $this->assertSame('required|string|min:2|in:' . $this->countryCodes(), $map['validate']['country']);
        $this->assertSame('nullable|min:5', $map['validate']['postcode']);
        $this->assertSame('Hà Nội', $map['dataAddress']['city']);
    }

    /**
     * @return array<string, string> Raw form with the always-present fields and an address.
     */
    private function raw(): array
    {
        return [
            'first_name' => 'An', 'email' => 'an-address-rules@example.com', 'password' => 'Secret#123',
            'password_confirmation' => 'Secret#123', 'id' => 'ZZ-ADDRESS-RULES',
            'city' => 'Hà Nội', 'address1' => '1 Tràng Tiền', 'country' => 'VN',
        ];
    }

    /**
     * @param array<string, mixed> $validate Rules of a form.
     * @return array<string, string> Its address rules, in the canonical field order.
     */
    private function addressRules(array $validate): array
    {
        $out = [];
        foreach (['city', 'district', 'ward', 'address1', 'address2', 'address3', 'country', 'postcode'] as $field) {
            if (array_key_exists($field, $validate)) {
                $out[$field] = (string) $validate[$field];
            }
        }

        return $out;
    }

    /**
     * @return array<string, string> Address rules that SWITCHES must produce.
     */
    private function expectedRules(): array
    {
        return [
            'city' => 'required|string|max:100',
            'district' => 'nullable|string|max:100',
            'address1' => 'required|string|max:100',
            'address2' => 'nullable|string|max:100',
            'address3' => 'required|string|max:100',
            'country' => 'required|string|min:2|in:' . $this->countryCodes(),
            'postcode' => 'nullable|min:5',
        ];
    }

    /**
     * @return string Comma-separated country codes of the shop.
     */
    private function countryCodes(): string
    {
        return implode(',', (new AdminCountry())->pluck('code')->toArray());
    }
}
