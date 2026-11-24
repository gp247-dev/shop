<?php

namespace GP247\Shop\Tests\Feature\ShopAddress;

use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Admin\Livewire\CustomerManager;
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
 * An administrator editing a customer or one of their addresses picks the province and ward
 * from the plugin's lists: the names and codes are checked and stored, and a customer whose
 * old address was typed by hand can still be edited without touching it.
 *
 * @aidlc-unit shop-address
 * @aidlc-story US-SADM-address-city-district
 * @aidlc-story US-shop-address-server-normalize
 * @aidlc-story US-shop-address-structured-meta
 */
#[Group('shop-address')]
class AddressAdminCustomerTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use ConfiguresAddressFields;

    protected function setUp(): void
    {
        parent::setUp();
        FakeAddressRegistry::bind(['FakeAddress' => new FakeAddressProvider()]);
        $this->configureAddress(['customer_city' => '1', 'customer_ward' => '1', 'customer_country' => '1']);
        $this->asUser(slugs: ['admin_customer']);
    }

    public function test_the_customer_form_shows_the_shared_block_with_selects(): void
    {
        $customer = $this->makeCustomer(['country' => 'VN', 'city' => 'Hà Nội']);

        $html = Livewire::test(CustomerManager::class, ['id' => (string) $customer->id])->html();

        $this->assertStringContainsString('data-testid="shop-admin-customer-block"', $html);
        $this->assertStringContainsString('data-testid="shop-admin-customer-city-select"', $html);
    }

    public function test_saving_a_customer_with_picked_codes_stores_the_plugin_names_and_codes(): void
    {
        $customer = $this->makeCustomer(['country' => 'VN', 'city' => 'Old City']);

        Livewire::test(CustomerManager::class, ['id' => (string) $customer->id])
            ->set('form.city', 'typed')->set('addressCodes.city', '79')
            ->set('form.ward', 'typed')->set('addressCodes.ward', '26734')
            ->call('save')
            ->assertHasNoErrors();

        $fresh = ShopCustomer::find($customer->id);
        $this->assertSame('Hồ Chí Minh', $fresh->city);
        $this->assertSame('Phường Sài Gòn', $fresh->ward);
        $this->assertSame('26734', $fresh->address_meta['components']['ward']['code']);
    }

    public function test_a_customer_with_an_old_typed_address_can_be_saved_without_touching_it(): void
    {
        $customer = $this->makeCustomer(['country' => 'VN', 'city' => 'Thành phố cũ']);

        Livewire::test(CustomerManager::class, ['id' => (string) $customer->id])
            ->set('form.first_name', 'Renamed')
            ->call('save')
            ->assertHasNoErrors();

        $fresh = ShopCustomer::find($customer->id);
        $this->assertSame('Renamed', $fresh->first_name);
        $this->assertSame('Thành phố cũ', $fresh->city);
    }

    public function test_editing_an_address_of_the_customer_stores_the_codes_and_refuses_a_ward_of_another_province(): void
    {
        $customer = $this->makeCustomer(['country' => 'VN']);
        $address = ShopCustomerAddress::create(['customer_id' => $customer->id, 'first_name' => 'An', 'address1' => '1 Old', 'country' => 'VN']);

        $component = Livewire::test(CustomerManager::class, ['id' => (string) $customer->id])
            ->call('editAddress', (string) $address->id)
            ->set('addressForm.city', 'x')->set('addressFormCodes.city', '01')
            ->set('addressForm.ward', 'Phường Sài Gòn')->set('addressFormCodes.ward', '26734')
            ->call('saveAddress')
            ->assertHasErrors(['addressForm.ward']);

        $component->set('addressForm.ward', 'x')->set('addressFormCodes.ward', '00004')
            ->call('saveAddress')
            ->assertHasNoErrors();

        $fresh = ShopCustomerAddress::find($address->id);
        $this->assertSame('Hà Nội', $fresh->city);
        $this->assertSame('Phường Ba Đình', $fresh->ward);
        $this->assertSame('00004', $fresh->address_meta['components']['ward']['code']);
    }

    /**
     * @param array<string, mixed> $attributes Extra columns.
     * @return ShopCustomer
     */
    private function makeCustomer(array $attributes = []): ShopCustomer
    {
        return ShopCustomer::create($attributes + [
            'first_name' => 'An', 'email' => 'an-admin-' . uniqid() . '@example.com', 'password' => bcrypt('Secret#123'),
            'status' => 1, 'address1' => '1 Tràng Tiền', 'store_id' => $this->rootStore(),
        ]);
    }
}
