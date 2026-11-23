<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Admin\Livewire\CustomerManager;
use GP247\Shop\Models\ShopCustomer;
use GP247\Shop\Models\ShopCustomerAddress;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\TestCase;

/**
 * E2E for the customer addresses sub-panel (1:N) on the customer edit screen:
 * list/add/edit/delete + set-default, config-driven validation (reusing
 * gp247_customer_address_mapping). Domain unchanged (ShopCustomerAddress).
 * Gated by `admin_customer`.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-004
 * @aidlc-adr ADR-001, ADR-006, ADR-007
 */
class CustomerAddressTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    /**
     * Create a customer (with one default address via createCustomer).
     *
     * @return ShopCustomer
     */
    private function makeCustomer(): ShopCustomer
    {
        return ShopCustomer::createCustomer([
            'first_name' => 'ZZ Addr',
            'last_name' => 'Owner',
            'email' => 'zz-addr-' . uniqid() . '@example.com',
            'password' => bcrypt('secret123'),
            'address1' => 'Default street',
            'country' => 'VN',
            'status' => 1,
            'store_id' => session('adminStoreId', GP247_STORE_ID_ROOT),
        ]);
    }

    /**
     * Fill a valid address form (covers all config-driven required fields).
     *
     * @param \Livewire\Features\SupportTesting\Testable $component
     * @return \Livewire\Features\SupportTesting\Testable
     */
    private function fillAddress($component)
    {
        return $component
            ->set('addressForm.first_name', 'New First')
            ->set('addressForm.last_name', 'New Last')
            ->set('addressForm.address1', 'New street 99')
            ->set('addressForm.address2', 'Ward 1')
            ->set('addressForm.address3', 'District 2')
            ->set('addressForm.country', 'VN')
            ->set('addressForm.phone', '0912345678')
            ->set('addressForm.postcode', '12345');
    }

    public function test_edit_loads_existing_addresses(): void
    {
        $this->asUser(slugs: ['admin_customer']);
        $customer = $this->makeCustomer();

        $component = Livewire::test(CustomerManager::class, ['id' => (string) $customer->id]);

        $this->assertCount(1, $component->get('addresses'));
        $this->assertSame((string) $customer->address_id, $component->get('defaultAddressId'));
    }

    public function test_add_address(): void
    {
        $this->asUser(slugs: ['admin_customer']);
        $customer = $this->makeCustomer();

        $component = Livewire::test(CustomerManager::class, ['id' => (string) $customer->id]);
        $this->fillAddress($component)->call('saveAddress')->assertHasNoErrors();

        $this->assertSame(2, ShopCustomerAddress::where('customer_id', $customer->id)->count());
    }

    public function test_add_address_validates_required(): void
    {
        $this->asUser(slugs: ['admin_customer']);
        $customer = $this->makeCustomer();

        Livewire::test(CustomerManager::class, ['id' => (string) $customer->id])
            ->set('addressForm.first_name', '')
            ->set('addressForm.address1', '')
            ->call('saveAddress')
            ->assertHasErrors(['addressForm.first_name', 'addressForm.address1']);
    }

    public function test_edit_address_updates(): void
    {
        $this->asUser(slugs: ['admin_customer']);
        $customer = $this->makeCustomer();
        $address = ShopCustomerAddress::where('customer_id', $customer->id)->first();

        $component = Livewire::test(CustomerManager::class, ['id' => (string) $customer->id])
            ->call('editAddress', (string) $address->id)
            ->assertSet('editingAddressId', (string) $address->id);

        $this->fillAddress($component)
            ->set('addressForm.first_name', 'Edited First')
            ->call('saveAddress')
            ->assertHasNoErrors();

        $this->assertSame('Edited First', ShopCustomerAddress::find($address->id)->first_name);
    }

    public function test_set_default_address(): void
    {
        $this->asUser(slugs: ['admin_customer']);
        $customer = $this->makeCustomer();

        $component = Livewire::test(CustomerManager::class, ['id' => (string) $customer->id]);
        $this->fillAddress($component)->call('saveAddress');

        $second = ShopCustomerAddress::where('customer_id', $customer->id)
            ->where('first_name', 'New First')->first();

        $component->call('setDefaultAddress', (string) $second->id);

        $this->assertSame((string) $second->id, (string) ShopCustomer::find($customer->id)->address_id);
    }

    public function test_delete_non_default_address(): void
    {
        $this->asUser(slugs: ['admin_customer']);
        $customer = $this->makeCustomer();

        $component = Livewire::test(CustomerManager::class, ['id' => (string) $customer->id]);
        $this->fillAddress($component)->call('saveAddress');
        $second = ShopCustomerAddress::where('customer_id', $customer->id)
            ->where('first_name', 'New First')->first();

        $component->call('deleteAddress', (string) $second->id);

        $this->assertNull(ShopCustomerAddress::find($second->id));
        $this->assertSame(1, ShopCustomerAddress::where('customer_id', $customer->id)->count());
    }

    public function test_cannot_delete_only_default_address(): void
    {
        $this->asUser(slugs: ['admin_customer']);
        $customer = $this->makeCustomer();
        $default = ShopCustomerAddress::where('customer_id', $customer->id)->first();

        Livewire::test(CustomerManager::class, ['id' => (string) $customer->id])
            ->call('deleteAddress', (string) $default->id);

        $this->assertNotNull(ShopCustomerAddress::find($default->id));
    }

    public function test_save_address_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);
        // Build a customer with the proper store via a privileged context is not
        // needed; create directly for the denied-path assertion.
        $customer = ShopCustomer::create([
            'first_name' => 'ZZ', 'email' => 'zz-deny-addr@example.com',
            'password' => bcrypt('x'), 'status' => 1,
            'store_id' => session('adminStoreId', GP247_STORE_ID_ROOT),
        ]);

        $component = Livewire::test(CustomerManager::class, ['id' => (string) $customer->id]);
        $this->fillAddress($component);

        $this->assertDenied(fn () => $component->call('saveAddress'));
    }
}
