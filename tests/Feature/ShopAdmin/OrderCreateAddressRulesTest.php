<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Core\Models\AdminRole;
use GP247\Core\Models\AdminUser;
use GP247\Shop\Tests\Feature\ShopAddress\Support\ConfiguresAddressFields;
use GP247\Shop\Tests\Feature\ShopAddress\Support\FakeAddressRegistry;
use GP247\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Group;

/**
 * An administrator creating an order is asked for the address fields the shop switched
 * on — the ward included — and only the ones marked required are mandatory.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-address-city-district
 */
#[Group('shop-admin')]
class OrderCreateAddressRulesTest extends TestCase
{
    use DatabaseTransactions;
    use ConfiguresAddressFields;

    protected function setUp(): void
    {
        parent::setUp();
        FakeAddressRegistry::bind([]);
        $this->loginAdministrator();
    }

    public function test_a_required_city_and_ward_must_be_filled_in(): void
    {
        $this->configureAddress(['customer_city' => '1', 'customer_city_required' => '1',
            'customer_ward' => '1', 'customer_ward_required' => '1']);

        $this->submit(['city' => '', 'ward' => ''])->assertSessionHasErrors(['city', 'ward']);
    }

    public function test_fields_that_are_shown_but_optional_may_be_left_empty(): void
    {
        $this->configureAddress(['customer_city' => '1', 'customer_district' => '1', 'customer_ward' => '1', 'customer_address2' => '1']);

        $this->submit(['city' => '', 'district' => '', 'ward' => '', 'address2' => ''])
            ->assertSessionDoesntHaveErrors(['city', 'district', 'ward', 'address2']);
    }

    public function test_fields_that_are_switched_off_are_not_asked_for(): void
    {
        $this->configureAddress();

        $this->submit([])->assertSessionDoesntHaveErrors(['city', 'district', 'ward', 'address2', 'address3', 'country', 'postcode']);
    }

    public function test_the_country_must_be_one_the_shop_knows(): void
    {
        $this->configureAddress(['customer_country' => '1', 'customer_country_required' => '1']);

        $this->submit(['country' => 'ZZ'])->assertSessionHasErrors(['country']);
        $this->submit(['country' => 'VN'])->assertSessionDoesntHaveErrors(['country']);
    }

    /**
     * Posts the create-order form with an incomplete order, so the request stops at
     * validation and only the address errors matter.
     *
     * @param array<string, string> $address Address fields to send.
     * @return \Illuminate\Testing\TestResponse
     */
    private function submit(array $address)
    {
        return $this->post(route('admin_order.post_create'), $address + [
            'first_name' => 'ZZ Address Rules', 'address1' => '1 Tràng Tiền',
            // WHY: no currency — the form always fails validation, nothing is written.
        ]);
    }

    /**
     * @return void
     */
    private function loginAdministrator(): void
    {
        $user = AdminUser::create([
            'id' => 'AU-ADR-' . substr(uniqid(), -6),
            'username' => 'address_admin_' . substr(uniqid(), -6),
            'password' => bcrypt('secret'),
            'name' => 'Address Admin',
            'email' => 'address' . substr(uniqid(), -6) . '@example.com',
            'status' => 1,
        ]);
        $role = AdminRole::firstOrCreate(['slug' => 'administrator'], ['name' => 'Administrator']);
        $user->roles()->syncWithoutDetaching([$role->id]);
        $this->actingAs($user, 'admin');
        session(['adminStoreId' => $this->rootStore()]);
    }
}
