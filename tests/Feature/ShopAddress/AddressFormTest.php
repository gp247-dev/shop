<?php

namespace GP247\Shop\Tests\Feature\ShopAddress;

use GP247\Shop\Address\AddressForm;
use GP247\Shop\Address\Data\AddressField;
use GP247\Shop\Tests\Feature\ShopAddress\Support\ConfiguresAddressFields;
use GP247\Shop\Tests\Feature\ShopAddress\Support\FakeAddressProvider;
use GP247\Shop\Tests\Feature\ShopAddress\Support\FakeAddressRegistry;
use GP247\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Group;

/**
 * One resolver answers, for a store, a country and a form: which address fields show,
 * which are required, in what order, how each is entered and how it is validated.
 * Without an address plugin the answer is what every form used before.
 *
 * @aidlc-unit shop-address
 * @aidlc-story US-shop-address-unified-field-rules
 * @aidlc-story US-shop-address-dependent-select
 * @aidlc-story US-shop-address-field-mapping
 * @aidlc-story US-shop-address-free-text-fallback
 */
#[Group('shop-address')]
class AddressFormTest extends TestCase
{
    use DatabaseTransactions;
    use ConfiguresAddressFields;

    public function test_without_an_address_plugin_every_enabled_field_is_a_text_input_in_the_usual_order(): void
    {
        FakeAddressRegistry::bind([]);
        $this->configureAddress(['customer_city' => '1', 'customer_ward' => '1', 'customer_address2' => '1',
            'customer_country' => '1', 'customer_postcode' => '1']);

        $set = AddressForm::make()->resolve(null, 'VN', AddressForm::CONTEXT_CHECKOUT);

        $this->assertFalse($set->hasProvider());
        $this->assertSame(['city', 'ward', 'address1', 'address2', 'country', 'postcode'], array_keys($set->visible()));
        foreach ($set->visible() as $field) {
            $this->assertSame(AddressField::INPUT_TEXT, $field->input, $field->name);
            $this->assertNull($field->component, $field->name);
        }
    }

    public function test_without_an_address_plugin_the_rules_follow_the_required_switch_of_each_field(): void
    {
        FakeAddressRegistry::bind([]);
        $this->configureAddress(['customer_city' => '1', 'customer_city_required' => '1', 'customer_district' => '1',
            'customer_ward' => '1', 'customer_address2' => '1', 'customer_postcode' => '1', 'customer_postcode_required' => '1']);

        $rules = AddressForm::make()->resolve(null, null, AddressForm::CONTEXT_CHECKOUT)->rules();

        $this->assertSame('required|string|max:100', $rules['city']);
        $this->assertSame('nullable|string|max:100', $rules['district']);
        $this->assertSame('nullable|string|max:100', $rules['ward']);
        $this->assertSame('required|string|max:100', $rules['address1']);
        $this->assertSame('nullable|string|max:100', $rules['address2']);
        $this->assertSame('required|min:5', $rules['postcode']);
        $this->assertArrayNotHasKey('address3', $rules);
        $this->assertArrayNotHasKey('country', $rules);
        $this->assertSame([], AddressForm::make()->resolve(null, null, AddressForm::CONTEXT_CHECKOUT)->codeRules());
    }

    public function test_the_country_rule_only_accepts_countries_of_the_shop(): void
    {
        FakeAddressRegistry::bind([]);
        $this->configureAddress(['customer_country' => '1', 'customer_country_required' => '1']);

        $rule = AddressForm::make()->resolve(null, null, AddressForm::CONTEXT_ADDRESS_BOOK)->rule('country');

        $this->assertStringStartsWith('required|string|min:2|in:', $rule);
        $this->assertStringContainsString('VN', $rule);
    }

    public function test_the_address_book_always_asks_for_the_main_address_and_honours_the_required_switch_of_the_others(): void
    {
        FakeAddressRegistry::bind([]);
        $this->configureAddress(['customer_address1_required' => '0', 'customer_address2' => '1', 'customer_address3' => '1',
            'customer_address3_required' => '1']);

        $set = AddressForm::make()->resolve(null, null, AddressForm::CONTEXT_ADDRESS_BOOK);

        $this->assertTrue($set->field('address1')->required);
        $this->assertSame('nullable|string|max:100', $set->rule('address2'));
        $this->assertSame('required|string|max:100', $set->rule('address3'));
    }

    public function test_a_customer_form_shows_the_main_address_and_follows_its_required_switch(): void
    {
        FakeAddressRegistry::bind([]);
        $this->configureAddress(['customer_address1_required' => '0']);

        $set = AddressForm::make()->resolve(null, null, AddressForm::CONTEXT_CUSTOMER_INSERT);

        $this->assertTrue($set->shows('address1'));
        $this->assertSame('nullable|string|max:100', $set->rule('address1'));
    }

    public function test_with_a_plugin_the_mapped_fields_become_dependent_selects_and_the_country_comes_first(): void
    {
        FakeAddressRegistry::bind(['FakeAddress' => new FakeAddressProvider()]);
        $this->configureAddress(['customer_city' => '1', 'customer_district' => '1', 'customer_ward' => '1', 'customer_country' => '1']);

        $set = AddressForm::make()->resolve(null, 'VN', AddressForm::CONTEXT_CHECKOUT);

        $this->assertSame('FakeAddress', $set->providerKey);
        $this->assertSame('2025-07', $set->dataVersion);
        $this->assertTrue($set->strict);
        $this->assertSame('country', array_key_first($set->visible()));
        $this->assertSame(AddressField::INPUT_SELECT, $set->field('city')->input);
        $this->assertSame('province', $set->field('city')->component);
        $this->assertNull($set->field('city')->parentField);
        $this->assertSame(AddressField::INPUT_SELECT, $set->field('ward')->input);
        $this->assertSame('city', $set->field('ward')->parentField);
        $this->assertSame(AddressField::INPUT_TEXT, $set->field('district')->input);
        $this->assertSame(['city_code' => 'nullable|string|max:64', 'ward_code' => 'nullable|string|max:64'], $set->codeRules());
        $this->assertArrayNotHasKey('city_code', $set->rules());
    }

    public function test_a_postcode_plugin_turns_the_postcode_into_a_lookup_and_prefills_editable_fields(): void
    {
        FakeAddressRegistry::bind(['FakeAddress' => new FakeAddressProvider()]);
        $this->configureAddress(['customer_city' => '1', 'customer_district' => '1', 'customer_postcode' => '1', 'customer_country' => '1']);

        $set = AddressForm::make()->resolve(null, 'JP', AddressForm::CONTEXT_CHECKOUT);

        $this->assertSame(AddressField::INPUT_LOOKUP, $set->field('postcode')->input);
        $this->assertSame(AddressField::INPUT_SELECT, $set->field('city')->input);
        $this->assertSame(AddressField::INPUT_TEXT, $set->field('district')->input);
        $this->assertSame('municipality', $set->field('district')->component);
        $this->assertFalse($set->field('district')->editable);
        $this->assertSame('town', $set->field('address1')->component);
        $this->assertTrue($set->field('address1')->editable);
    }

    public function test_a_field_switched_off_is_never_shown_even_when_the_plugin_maps_it(): void
    {
        FakeAddressRegistry::bind(['FakeAddress' => new FakeAddressProvider()]);
        $this->configureAddress(['customer_city' => '1', 'customer_ward' => '0', 'customer_country' => '1']);

        $set = AddressForm::make()->resolve(null, 'VN', AddressForm::CONTEXT_CHECKOUT);

        $this->assertTrue($set->hasProvider());
        $this->assertFalse($set->shows('ward'));
        $this->assertArrayNotHasKey('ward', $set->mapped());
        $this->assertArrayNotHasKey('ward_code', $set->codeRules());
    }

    public function test_the_mapping_saved_by_the_shop_owner_wins_over_the_plugin_default(): void
    {
        FakeAddressRegistry::bind(['FakeAddress' => new FakeAddressProvider()]);
        $this->configureAddress(['customer_city' => '1', 'customer_district' => '1', 'customer_ward' => '1', 'customer_country' => '1',
            'address_mapping_VN' => json_encode(['provider' => 'FakeAddress', 'strict' => false,
                'fields' => ['city' => ['component' => 'province'], 'district' => ['component' => 'ward']]])]);

        $set = AddressForm::make()->resolve(null, 'VN', AddressForm::CONTEXT_CHECKOUT);

        $this->assertFalse($set->strict);
        $this->assertSame('ward', $set->field('district')->component);
        $this->assertSame('city', $set->field('district')->parentField);
        $this->assertNull($set->field('ward')->component);
        $this->assertSame(AddressField::INPUT_TEXT, $set->field('ward')->input);
    }

    public function test_a_child_component_whose_parent_is_not_shown_falls_back_to_a_text_input(): void
    {
        FakeAddressRegistry::bind(['FakeAddress' => new FakeAddressProvider()]);
        $this->configureAddress(['customer_city' => '0', 'customer_ward' => '1', 'customer_country' => '1']);

        $set = AddressForm::make()->resolve(null, 'VN', AddressForm::CONTEXT_CHECKOUT);

        $this->assertTrue($set->hasProvider());
        $this->assertSame(AddressField::INPUT_TEXT, $set->field('ward')->input);
        $this->assertNull($set->field('ward')->component);
    }

    public function test_a_shop_that_hides_the_country_uses_the_only_country_a_plugin_serves(): void
    {
        FakeAddressRegistry::bind(['FakeAddress' => new FakeAddressProvider()], ['FakeAddress' => ['VN']]);
        $this->configureAddress(['customer_city' => '1', 'customer_country' => '0']);

        $set = AddressForm::make()->resolve(null, null, AddressForm::CONTEXT_CHECKOUT);

        $this->assertSame('VN', $set->country);
        $this->assertSame(AddressField::INPUT_SELECT, $set->field('city')->input);
    }

    public function test_a_shop_that_hides_the_country_ignores_a_country_sent_anyway(): void
    {
        FakeAddressRegistry::bind(['FakeAddress' => new FakeAddressProvider()], ['FakeAddress' => ['VN']]);
        $this->configureAddress(['customer_city' => '1', 'customer_country' => '0']);

        $set = AddressForm::make()->resolve(null, 'ZZ', AddressForm::CONTEXT_CHECKOUT);

        $this->assertSame('VN', $set->country);
        $this->assertTrue($set->hasProvider());
    }

    public function test_a_country_no_plugin_serves_keeps_text_inputs(): void
    {
        FakeAddressRegistry::bind(['FakeAddress' => new FakeAddressProvider()], ['FakeAddress' => ['VN']]);
        $this->configureAddress(['customer_city' => '1', 'customer_country' => '1']);

        $set = AddressForm::make()->resolve(null, 'US', AddressForm::CONTEXT_CHECKOUT);

        $this->assertFalse($set->hasProvider());
        $this->assertSame(AddressField::INPUT_TEXT, $set->field('city')->input);
    }

    public function test_a_plugin_that_cannot_describe_its_fields_leaves_the_form_as_free_text(): void
    {
        $provider = new FakeAddressProvider();
        $provider->failing = ['schema'];
        FakeAddressRegistry::bind(['FakeAddress' => $provider]);
        $this->configureAddress(['customer_city' => '1']);

        $set = AddressForm::make()->resolve(null, 'VN', AddressForm::CONTEXT_CHECKOUT);

        $this->assertFalse($set->hasProvider());
        $this->assertSame(AddressField::INPUT_TEXT, $set->field('city')->input);
    }
}
