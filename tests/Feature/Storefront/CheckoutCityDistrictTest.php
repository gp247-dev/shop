<?php

namespace GP247\Shop\Tests\Feature\Storefront;

use GP247\Core\Models\AdminConfig;
use GP247\Shop\Front\Livewire\CheckoutWizard;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Shop\Tests\TestCase;

/**
 * Storefront checkout gating for the new administrative address fields city /
 * district (canonical order: city -> district -> address1/2/3). Both default
 * OFF, so an unchanged install renders and validates exactly as before. When
 * enabled they render; when marked required they block advancing from the
 * address step. Same customer_config_attribute[_required] keys the server-side
 * gp247_order_mapping_validate() reads, so display and validation agree.
 *
 * @aidlc-unit storefront
 * @aidlc-story US-CMP-address-city-district-schema
 * @aidlc-adr shop-admin_address-city-district
 */
class CheckoutCityDistrictTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Upsert a customer_config_attribute[_required] key and clear AdminConfig's
     * per-request static caches so gp247_config() re-reads from DB immediately.
     * WHY: DatabaseTransactions rolls back the row but not the static cache.
     *
     * @param string $key   Config key (e.g. customer_city, customer_city_required).
     * @param string $value '1' (on) or '0' (off).
     * @param string $code  customer_config_attribute or *_required.
     * @return void
     */
    private function setCustomerConfig(string $key, string $value, string $code = 'customer_config_attribute'): void
    {
        $storeId = defined('GP247_STORE_ID_GLOBAL') ? GP247_STORE_ID_GLOBAL : 0;

        AdminConfig::updateOrCreate(
            ['key' => $key, 'store_id' => $storeId],
            ['group' => 'gp247_cart', 'code' => $code, 'value' => $value, 'sort' => 0, 'detail' => '']
        );

        // getAllConfigOfStore uses array_key_exists() (must stay an array);
        // getAllGlobal checks === null. Reset each to its "force re-read" empty.
        $store = new \ReflectionProperty(AdminConfig::class, 'getAllConfigOfStore');
        $store->setAccessible(true);
        $store->setValue(null, []);

        $global = new \ReflectionProperty(AdminConfig::class, 'getAllGlobal');
        $global->setAccessible(true);
        $global->setValue(null, null);
    }

    public function test_city_district_hidden_when_config_disabled(): void
    {
        $this->setCustomerConfig('customer_city', '0');
        $this->setCustomerConfig('customer_district', '0');

        $html = Livewire::test(CheckoutWizard::class)->html();

        // The address fields come from the shared address block (US-LW-checkout-city-district).
        $this->assertStringNotContainsString('data-testid="checkout-address-city"', $html);
        $this->assertStringNotContainsString('data-testid="checkout-address-district"', $html);
    }

    public function test_city_district_shown_when_config_enabled(): void
    {
        $this->setCustomerConfig('customer_city', '1');
        $this->setCustomerConfig('customer_district', '1');

        $html = Livewire::test(CheckoutWizard::class)->html();

        $this->assertStringContainsString('data-testid="checkout-address-city"', $html);
        $this->assertStringContainsString('data-testid="checkout-address-district"', $html);
    }

    public function test_city_district_not_required_when_disabled(): void
    {
        // Default OFF: leaving city/district empty must never block advancing,
        // even with every always-required field filled (no regression).
        $this->setCustomerConfig('customer_city', '0');
        $this->setCustomerConfig('customer_district', '0');

        Livewire::test(CheckoutWizard::class)
            ->set('first_name', 'John')
            ->set('email', 'john@example.com')
            ->set('address1', '123 Test Street')
            ->call('nextStep')
            ->assertHasNoErrors(['city', 'district']);
    }

    public function test_city_district_optional_when_shown_but_not_required(): void
    {
        // Shown but not required: empty is still acceptable.
        $this->setCustomerConfig('customer_city', '1');
        $this->setCustomerConfig('customer_city_required', '0', 'customer_config_attribute_required');
        $this->setCustomerConfig('customer_district', '1');
        $this->setCustomerConfig('customer_district_required', '0', 'customer_config_attribute_required');

        Livewire::test(CheckoutWizard::class)
            ->set('first_name', 'John')
            ->set('email', 'john@example.com')
            ->set('address1', '123 Test Street')
            ->call('nextStep')
            ->assertHasNoErrors(['city', 'district']);
    }

    public function test_city_district_required_block_advance_when_empty(): void
    {
        $this->setCustomerConfig('customer_city', '1');
        $this->setCustomerConfig('customer_city_required', '1', 'customer_config_attribute_required');
        $this->setCustomerConfig('customer_district', '1');
        $this->setCustomerConfig('customer_district_required', '1', 'customer_config_attribute_required');

        Livewire::test(CheckoutWizard::class)
            ->set('first_name', 'John')
            ->set('email', 'john@example.com')
            ->set('address1', '123 Test Street')
            ->set('city', '')
            ->set('district', '')
            ->call('nextStep')
            ->assertHasErrors(['city', 'district']);
    }

    public function test_city_district_required_pass_when_filled(): void
    {
        $this->setCustomerConfig('customer_city', '1');
        $this->setCustomerConfig('customer_city_required', '1', 'customer_config_attribute_required');
        $this->setCustomerConfig('customer_district', '1');
        $this->setCustomerConfig('customer_district_required', '1', 'customer_config_attribute_required');

        Livewire::test(CheckoutWizard::class)
            ->set('first_name', 'John')
            ->set('email', 'john@example.com')
            ->set('address1', '123 Test Street')
            ->set('city', 'Ha Noi')
            ->set('district', 'Cau Giay')
            ->call('nextStep')
            ->assertHasNoErrors(['city', 'district']);
    }
}
