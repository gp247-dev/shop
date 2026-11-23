<?php

namespace GP247\Shop\Tests\Feature\ShopShipping;

use GP247\Core\AdminShell\Infrastructure\ReadinessCheck;
use GP247\Core\Models\AdminConfig;
use GP247\Shop\Shipping\ShippingSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Group;
use GP247\Shop\Tests\Feature\ShopShipping\Support\FakeCarrier;
use GP247\Shop\Tests\Feature\ShopShipping\Support\FakeShippingRegistry;
use GP247\Shop\Tests\TestCase;

/**
 * One place answers "what does this store still need before a carrier can quote?": the
 * shipping switch, the province and ward fields of customer addresses, the pick-up
 * warehouse and an enabled carrier — read fresh, for the store being checked.
 *
 * @aidlc-unit shop-shipping
 * @aidlc-story US-shop-shipping-readiness-check
 */
#[Group('shop-shipping')]
class ShippingReadinessTest extends TestCase
{
    use DatabaseTransactions;

    private const COMPLETE = [
        'use_shipping' => '1', 'customer_city' => '1', 'customer_ward' => '1', 'customer_district' => '0',
        'shipping_origin_province' => 'Hà Nội', 'shipping_origin_address' => '1 Tràng Tiền', 'shipping_origin_phone' => '0900000000',
    ];

    public function test_every_missing_setting_is_an_error_with_a_link_to_fix_it(): void
    {
        FakeShippingRegistry::bind([]);
        $this->setGlobal(['use_shipping' => '0', 'customer_city' => '0', 'customer_ward' => '0', 'customer_district' => '0',
            'shipping_origin_province' => '', 'shipping_origin_address' => '', 'shipping_origin_phone' => '']);

        $checks = $this->byKey(ShippingSettings::readiness($this->root()));

        foreach (['shipping_enabled', 'customer_city', 'customer_ward', 'origin', 'provider_enabled'] as $key) {
            $this->assertSame('error', $checks[$key]->level, $key);
            $this->assertNotSame('', $checks[$key]->fixUrl, $key);
            $this->assertNotSame('', $checks[$key]->message, $key);
        }
        $this->assertSame(gp247_language_render('admin.shipping.warn_city'), $checks['customer_city']->message);
    }

    public function test_a_complete_store_with_a_carrier_passes_every_check(): void
    {
        $this->setGlobal(self::COMPLETE);
        FakeShippingRegistry::bind(['FakeCarrier' => FakeCarrier::withServices(['std' => 1])]);

        foreach (ShippingSettings::readiness($this->root()) as $check) {
            $this->assertTrue($check->passed(), $check->key);
        }
    }

    public function test_a_district_field_is_enough_where_wards_are_not_asked(): void
    {
        $this->setGlobal(['customer_ward' => '0', 'customer_district' => '1'] + self::COMPLETE);
        FakeShippingRegistry::bind([]);

        $this->assertTrue($this->byKey(ShippingSettings::readiness($this->root()))['customer_ward']->passed());
    }

    public function test_a_named_carrier_must_be_enabled_for_the_store_being_checked(): void
    {
        $this->setGlobal(self::COMPLETE);
        FakeShippingRegistry::bind(['FakeCarrier' => FakeCarrier::withServices(['std' => 1])]);
        AdminConfig::updateOrCreate(['group' => 'Plugins', 'key' => 'FakeCarrier', 'store_id' => (string) GP247_STORE_ID_GLOBAL],
            ['code' => 'Shipping', 'value' => '0', 'sort' => 0, 'detail' => '']);

        $this->assertSame('error', $this->byKey(ShippingSettings::readiness($this->root(), 'FakeCarrier'))['provider_enabled']->level);

        AdminConfig::where('group', 'Plugins')->where('key', 'FakeCarrier')->update(['value' => '1']);

        $this->assertTrue($this->byKey(ShippingSettings::readiness($this->root(), 'FakeCarrier'))['provider_enabled']->passed());
    }

    public function test_values_being_edited_win_over_the_saved_ones(): void
    {
        $this->setGlobal(self::COMPLETE);
        FakeShippingRegistry::bind([]);

        $checks = $this->byKey(ShippingSettings::readiness($this->root(), null, ['customer_city' => '0', 'shipping_origin_phone' => '']));

        $this->assertSame('error', $checks['customer_city']->level);
        $this->assertSame('error', $checks['origin']->level);
        $this->assertTrue($checks['customer_ward']->passed());
    }

    public function test_a_setting_changed_during_the_request_is_seen_at_once(): void
    {
        $this->setGlobal(self::COMPLETE);
        FakeShippingRegistry::bind([]);
        gp247_config('customer_city', $this->root()); // warm the per-request cache

        AdminConfig::where('key', 'customer_city')->update(['value' => '0']);

        $this->assertSame('error', $this->byKey(ShippingSettings::readiness($this->root()))['customer_city']->level);
    }

    /**
     * @param array<int, ReadinessCheck> $checks Checks.
     * @return array<string, ReadinessCheck>
     */
    private function byKey(array $checks): array
    {
        $out = [];
        foreach ($checks as $check) {
            $out[$check->key] = $check;
        }

        return $out;
    }

    /**
     * @return string
     */
    private function root(): string
    {
        return (string) (defined('GP247_STORE_ID_ROOT') ? GP247_STORE_ID_ROOT : '1');
    }

    /**
     * @param array<string, string> $values key => value at GLOBAL and ROOT rows when present.
     * @return void
     */
    private function setGlobal(array $values): void
    {
        foreach ($values as $key => $value) {
            AdminConfig::where('key', $key)->whereIn('store_id', [(string) GP247_STORE_ID_GLOBAL, $this->root()])->update(['value' => $value]);
        }
        foreach (['getAllGlobal' => null, 'getAllConfigOfStore' => []] as $name => $empty) {
            $prop = new \ReflectionProperty(AdminConfig::class, $name);
            $prop->setValue(null, $empty);
        }
    }
}
