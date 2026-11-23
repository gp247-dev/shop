<?php

namespace GP247\Shop\Tests\Feature\Storefront;

use GP247\Core\Models\AdminConfig;
use GP247\Shop\Front\Livewire\CheckoutWizard;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Shop\Tests\TestCase;

/**
 * Verifies the checkout address step reads its show/required field flags from
 * the admin `customer_config_attribute` / `customer_config_attribute_required`
 * toggles (US-SADM-005), the same keys gp247_order_mapping_validate() already
 * uses for server-side validation. Ensures display, validation, and order
 * creation all agree on which optional address fields are active.
 *
 * @aidlc-unit storefront
 * @aidlc-story US-LW-006
 */
class CheckoutFieldConfigTest extends TestCase
{
    // WHY: setCustomerConfig() writes real admin_config rows. Without this the
    // suite left customer_company switched off in the dev database after every run.
    use DatabaseTransactions;

    /**
     * Set a customer_config_attribute key and clear AdminConfig's per-request
     * static caches so gp247_config() re-reads from DB immediately (same
     * pattern as ProductCompositionTest::enableStructureType()).
     */
    private function setCustomerConfig(string $key, string $value): void
    {
        $storeId = defined('GP247_STORE_ID_GLOBAL') ? GP247_STORE_ID_GLOBAL : 0;

        AdminConfig::updateOrCreate(
            ['key' => $key, 'store_id' => $storeId],
            ['group' => 'gp247_cart', 'code' => 'customer_config_attribute', 'value' => $value, 'sort' => 0, 'detail' => '']
        );

        // The two memos have different empty values: getAllGlobal is a nullable
        // array, getAllConfigOfStore is an array KEYED BY STORE and is read with
        // array_key_exists(), so nulling it makes the next config read a TypeError
        // rather than a cache miss. Mirrors CheckoutWizardTest::resetAdminConfigMemo().
        foreach (['getAllConfigOfStore', 'getAllGlobal'] as $propName) {
            $prop = new \ReflectionProperty(AdminConfig::class, $propName);
            $prop->setAccessible(true);
            $prop->setValue(null, $propName === 'getAllGlobal' ? null : []);
        }
    }

    public function test_field_hidden_when_customer_config_attribute_disabled(): void
    {
        $this->setCustomerConfig('customer_company', '0');

        $html = Livewire::test(CheckoutWizard::class)->html();

        $this->assertStringNotContainsString(
            'wire:model="company"',
            $html,
            'company field must not render when customer_company is disabled in admin config (US-LW-006)'
        );
    }

    public function test_field_shown_when_customer_config_attribute_enabled(): void
    {
        $this->setCustomerConfig('customer_company', '1');

        $html = Livewire::test(CheckoutWizard::class)->html();

        $this->assertStringContainsString(
            'wire:model="company"',
            $html,
            'company field must render when customer_company is enabled in admin config (US-LW-006)'
        );
    }

    public function test_hidden_field_is_not_required_in_server_side_validation(): void
    {
        $this->setCustomerConfig('customer_company', '0');

        // A disabled field must not block advancing even when every other
        // possible required field is filled and company is left empty.
        $component = Livewire::test(CheckoutWizard::class)
            ->set('first_name', 'John')
            ->set('email', 'john@example.com')
            ->set('address1', '123 Test Street')
            ->call('nextStep');

        $component->assertHasNoErrors('company');
    }
}
