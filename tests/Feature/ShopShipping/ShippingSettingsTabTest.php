<?php

/**
 * @aidlc-story US-shop-shipping-origin-config
 */

namespace GP247\Shop\Tests\Feature\ShopShipping;

use GP247\Core\Models\AdminConfig;
use GP247\Shop\Admin\Livewire\ShopConfigForm;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\Feature\ShopShipping\Support\FakeCarrier;
use GP247\Shop\Tests\Feature\ShopShipping\Support\FakeShippingRegistry;
use GP247\Shop\Tests\TestCase;

/**
 * Everything about shipping is set on one tab: the on/off switch with its status, the
 * carriers in use, the pick-up warehouse, the parcel default and tracking — in a fixed,
 * readable order, with a hint under every field and a warning for what is still missing.
 */
#[Group('shop-shipping')]
class ShippingSettingsTabTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->asUser(slugs: ['admin_shop_config']);
        $this->setGlobal(['use_shipping' => '0', 'customer_city' => '0', 'customer_ward' => '0', 'customer_district' => '0',
            'shipping_origin_province' => '', 'shipping_origin_address' => '', 'shipping_origin_phone' => '']);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->resetConfigCache();
        parent::tearDown();
    }

    public function test_the_switch_lives_on_the_shipping_tab_and_no_longer_on_the_order_tab(): void
    {
        $tabs = collect(Livewire::test(ShopConfigForm::class)->viewData('tabs'))->keyBy('id');

        $this->assertContains('use_shipping', array_column($tabs['shipping']['fields'], 'key'));
        $this->assertNotContains('use_shipping', array_column($tabs['order']['fields'], 'key'));
    }

    public function test_fields_follow_the_blocks_in_a_fixed_order_and_each_has_a_hint(): void
    {
        $fields = collect(Livewire::test(ShopConfigForm::class)->viewData('tabs'))->firstWhere('id', 'shipping')['fields'];

        $this->assertSame([
            'use_shipping',
            'shipping_origin_name', 'shipping_origin_phone', 'shipping_origin_country', 'shipping_origin_province',
            'shipping_origin_ward', 'shipping_origin_district', 'shipping_origin_address',
            'shipping_default_weight',
            'shipping_sync_interval', 'shipping_cod_auto_record',
        ], array_column($fields, 'key'));
        $this->assertSame(['status', 'origin', 'origin', 'origin', 'origin', 'origin', 'origin', 'origin', 'parcel', 'tracking', 'tracking'], array_column($fields, 'group'));
        foreach ($fields as $field) {
            $this->assertNotSame('', $field['note'], $field['key'] . ' has a hint');
        }
    }

    public function test_while_off_the_tab_says_so_and_lists_what_is_still_missing(): void
    {
        FakeShippingRegistry::bind([]);

        Livewire::test(ShopConfigForm::class)
            ->assertSee('data-testid="shop-config-shipping-status-off"', false)
            ->assertSee(gp247_language_render('admin.shipping.warn_city'))
            ->assertSee(gp247_language_render('admin.shipping.warn_ward'))
            ->assertSee(gp247_language_render('admin.shipping.warn_origin'))
            ->assertSee(gp247_language_render('admin.shipping.warn_no_provider'));
    }

    public function test_when_on_and_complete_the_tab_shows_the_carriers_and_no_warning(): void
    {
        $this->setGlobal(['use_shipping' => '1', 'customer_city' => '1', 'customer_ward' => '1',
            'shipping_origin_province' => 'Hà Nội', 'shipping_origin_address' => '1 Tràng Tiền', 'shipping_origin_phone' => '0900000000']);
        config(['gp247-config.shop.shipping_providers.FakeCarrier' => ['driver' => FakeCarrier::class, 'capabilities' => ['rates', 'shipments']]]);
        FakeShippingRegistry::bind(['FakeCarrier' => FakeCarrier::withServices(['std' => 1])]);

        Livewire::test(ShopConfigForm::class)
            ->assertSee('data-testid="shop-config-shipping-status-on"', false)
            ->assertSee('data-testid="shop-config-shipping-provider-FakeCarrier"', false)
            ->assertDontSee(gp247_language_render('admin.shipping.warn_origin'))
            ->assertDontSee(gp247_language_render('admin.shipping.warn_no_provider'));
    }

    public function test_weight_interval_and_country_are_validated_before_saving(): void
    {
        Livewire::test(ShopConfigForm::class)
            ->set('values.shipping_default_weight', '0')
            ->set('values.shipping_sync_interval', '2')
            ->set('values.shipping_origin_country', 'Vietnam')
            ->call('save')
            ->assertHasErrors(['values.shipping_default_weight', 'values.shipping_sync_interval', 'values.shipping_origin_country']);

        $this->assertNotSame('0', (string) AdminConfig::where('key', 'shipping_default_weight')->where('store_id', $this->global())->value('value'));
    }

    /**
     * @param array<string, string> $values key => value at GLOBAL (and ROOT rows when present).
     * @return void
     */
    private function setGlobal(array $values): void
    {
        $root = (string) (defined('GP247_STORE_ID_ROOT') ? GP247_STORE_ID_ROOT : '1');
        foreach ($values as $key => $value) {
            AdminConfig::where('key', $key)->whereIn('store_id', [$this->global(), $root])->update(['value' => $value]);
        }
        $this->resetConfigCache();
    }

    /**
     * @return string
     */
    private function global(): string
    {
        return (string) (defined('GP247_STORE_ID_GLOBAL') ? GP247_STORE_ID_GLOBAL : '0');
    }

    /**
     * @return void
     */
    private function resetConfigCache(): void
    {
        foreach (['getAllGlobal' => null, 'getAllConfigOfStore' => []] as $name => $empty) {
            $prop = new \ReflectionProperty(AdminConfig::class, $name);
            $prop->setValue(null, $empty);
        }
    }
}
