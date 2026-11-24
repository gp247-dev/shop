<?php

namespace GP247\Shop\Tests\Feature\ShopAddress;

use GP247\Core\Models\AdminConfig;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Address\AddressForm;
use GP247\Shop\Address\AddressMapping;
use GP247\Shop\Address\AddressSettings;
use GP247\Shop\Admin\Livewire\AddressMappingForm;
use GP247\Shop\Tests\Feature\ShopAddress\Support\ConfiguresAddressFields;
use GP247\Shop\Tests\Feature\ShopAddress\Support\FakeAddressProvider;
use GP247\Shop\Tests\Feature\ShopAddress\Support\FakeAddressRegistry;
use GP247\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;

/**
 * The shop owner decides, per country, which address field takes which component of the
 * address plugin, whether a value outside the list is refused — and sees what is still
 * missing before it works.
 *
 * @aidlc-unit shop-address
 * @aidlc-story US-shop-address-field-mapping
 * @aidlc-story US-shop-address-readiness-check
 */
#[Group('shop-address')]
class AddressMappingFormTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use ConfiguresAddressFields;

    protected function setUp(): void
    {
        parent::setUp();
        FakeAddressRegistry::bind(['FakeAddress' => new FakeAddressProvider()], ['FakeAddress' => ['VN', 'JP']]);
        $this->configureAddress(['customer_city' => '1', 'customer_district' => '1', 'customer_ward' => '1', 'customer_country' => '1']);
        AdminConfig::whereIn('key', ['address_mapping_VN', 'address_mapping_JP'])->delete();
        AdminConfig::forgetMemo();
        $this->asUser(slugs: ['admin_shop_config']);
        session(['adminStoreId' => $this->rootStore()]);
    }

    public function test_the_tab_lists_the_plugin_components_with_the_shop_field_each_one_fills(): void
    {
        Livewire::test(AddressMappingForm::class)
            ->assertSet('country', 'JP')
            ->call('selectCountry', 'VN')
            ->assertSet('country', 'VN')
            ->assertSet('targets.province', 'city')
            ->assertSet('targets.ward', 'ward')
            ->assertSet('strict', true)
            // One row per component of the plugin, not one per shop field.
            ->assertSee('data-testid="shop-admin-address-mapping-province"', false)
            ->assertSee('data-testid="shop-admin-address-mapping-ward"', false)
            ->assertDontSee('data-testid="shop-admin-address-mapping-address1"', false)
            ->assertSee('data-testid="shop-admin-address-mapping-country-VN"', false)
            ->assertSee('data-testid="shop-admin-address-mapping-strict-refuse"', false)
            ->assertSee('data-testid="shop-admin-address-mapping-strict-accept"', false)
            ->assertSee(gp247_language_render('admin.address_mapping.not_used'))
            // Both columns are named and the step says what it does.
            ->assertSee(gp247_language_render('admin.address_mapping.col_plugin_list'))
            ->assertSee(gp247_language_render('admin.address_mapping.col_shop_field'))
            ->assertSee(gp247_language_render('admin.address_mapping.step_targets_help'));
    }

    public function test_the_preview_says_which_fields_customers_pick_and_which_they_type(): void
    {
        $component = Livewire::test(AddressMappingForm::class)->call('selectCountry', 'VN');
        $preview = $component->viewData('preview');

        $this->assertSame('list', $preview['city']['mode']);
        $this->assertSame('list', $preview['ward']['mode']);
        $this->assertSame('typed', $preview['district']['mode']);
        $this->assertArrayNotHasKey('address3', $preview, 'a switched-off field is not shown to customers');

        $component->set('targets.ward', '');
        $this->assertSame('typed', $component->viewData('preview')['ward']['mode']);
    }

    public function test_saving_a_mapping_changes_how_the_forms_use_the_plugin(): void
    {
        Livewire::test(AddressMappingForm::class)
            ->call('selectCountry', 'VN')
            ->set('targets.ward', 'district')
            ->set('strict', false)
            ->call('save')
            ->assertHasNoErrors();

        $set = AddressForm::make()->resolve(null, 'VN', AddressForm::CONTEXT_CHECKOUT);
        $this->assertSame('ward', $set->field('district')->component);
        $this->assertNull($set->field('ward')->component);
        $this->assertFalse($set->strict);
    }

    public function test_a_derived_component_keeps_its_editable_choice(): void
    {
        Livewire::test(AddressMappingForm::class)
            ->assertSet('targets.town', 'address1')
            ->assertSet('targetEditable.town', true)
            ->set('targetEditable.town', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse(AddressForm::make()->resolve(null, 'JP', AddressForm::CONTEXT_CHECKOUT)->field('address1')->editable);
    }

    public function test_a_child_without_its_parent_and_two_lists_in_one_field_are_refused(): void
    {
        Livewire::test(AddressMappingForm::class)
            ->call('selectCountry', 'VN')
            ->set('targets.province', '')
            ->call('save')
            ->assertHasErrors(['targets.ward']);

        Livewire::test(AddressMappingForm::class)
            ->call('selectCountry', 'VN')
            ->set('targets.ward', 'city')
            ->call('save')
            ->assertHasErrors(['targets.ward']);

        $this->assertNull((new AddressMapping())->stored(null, 'VN'));
    }

    public function test_a_field_outside_the_shop_fields_is_refused(): void
    {
        Livewire::test(AddressMappingForm::class)
            ->call('selectCountry', 'VN')
            ->set('targets.ward', 'password')
            ->call('save')
            ->assertHasErrors(['targets.ward']);

        $this->assertNull((new AddressMapping())->stored(null, 'VN'));
    }

    public function test_back_to_default_drops_the_saved_mapping(): void
    {
        $component = Livewire::test(AddressMappingForm::class)->call('selectCountry', 'VN')
            ->set('targets.province', '')->set('targets.ward', '')->call('save');
        $this->assertNotNull((new AddressMapping())->stored(null, 'VN'));

        $component->call('resetToDefault')->assertSet('targets.province', 'city');

        $this->assertNull((new AddressMapping())->stored(null, 'VN'));
    }

    public function test_a_country_no_plugin_serves_cannot_be_saved_or_reset_from_a_tampered_form(): void
    {
        AdminConfig::setConfigValue('gp247_address', 'address_mapping_US', (string) GP247_STORE_ID_GLOBAL, '{"provider":"Other","fields":{}}');
        AdminConfig::forgetMemo();

        Livewire::test(AddressMappingForm::class)->set('country', 'US')->call('resetToDefault')->call('save');

        $this->assertNotNull((new AddressMapping())->stored(null, 'US'));
    }

    public function test_a_country_given_in_the_link_opens_directly(): void
    {
        Livewire::withQueryParams(['country' => 'VN'])->test(AddressMappingForm::class)->assertSet('country', 'VN');
    }

    public function test_readiness_names_a_mapped_field_that_is_switched_off_and_a_hidden_country(): void
    {
        $this->configureAddress(['customer_city' => '1', 'customer_ward' => '0', 'customer_country' => '0']);

        $checks = collect(AddressSettings::readiness((string) GP247_STORE_ID_GLOBAL, 'VN'))->keyBy('key');

        $this->assertSame('error', $checks['fields_on']->level);
        $this->assertStringContainsString((string) gp247_language_render('customer.ward'), $checks['fields_on']->message);
        $this->assertNotSame('', $checks['fields_on']->fixUrl);
        $this->assertSame('warning', $checks['country_hidden']->level);
        $this->assertTrue($checks['parents']->passed());
    }

    public function test_readiness_is_clean_when_everything_is_in_place(): void
    {
        foreach (AddressSettings::readiness((string) GP247_STORE_ID_GLOBAL, 'VN') as $check) {
            $this->assertTrue($check->passed() || $check->key === 'template', $check->key . ': ' . $check->message);
        }
    }

    public function test_a_country_without_a_plugin_has_nothing_to_configure(): void
    {
        FakeAddressRegistry::bind([]);

        Livewire::test(AddressMappingForm::class)
            ->assertSet('country', '')
            ->assertSee('data-testid="shop-admin-address-mapping-empty"', false);
        $this->assertSame('warning', AddressSettings::readiness((string) GP247_STORE_ID_GLOBAL, 'VN')[0]->level);
    }
}
