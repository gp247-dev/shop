<?php

namespace GP247\Shop\Tests\Feature\Compat;

use GP247\Shop\Models\ShopCustomerAddress;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * The signed-in customer manages the address book through API v2: add, list, edit, set
 * the default, delete — with the store's address rules. Another customer's address is
 * "not found", and the default address cannot be deleted.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-customer-address
 * @aidlc-adr compat-foundation_api-token-rotation
 */
class ApiCustomerAddressTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useMinimalCustomerForm();
    }

    private function tokenOf($customer): string
    {
        return $this->tokenFor($customer, ['user']);
    }

    public function test_the_customer_adds_lists_and_edits_addresses(): void
    {
        $customer = $this->makeCustomer();
        $token = $this->tokenOf($customer);

        $added = $this->apiCall('POST', $this->v2Front() . '/member/address', $token, ['first_name' => 'ZZ Home', 'address1' => '1 ZZ road'])
            ->assertStatus(201)->json('data');
        $this->apiCall('PUT', $this->v2Front() . '/member/address/' . $added['id'], $token, ['first_name' => 'ZZ Home', 'address1' => '2 ZZ road'])
            ->assertOk()->assertJson(['data' => ['address1' => '2 ZZ road']]);

        $list = $this->apiCall('GET', $this->v2Front() . '/member/address/list', $token)->assertOk()->json('data');
        $this->assertSame(['2 ZZ road'], array_column($list, 'address1'));
    }

    public function test_an_address_can_become_the_default(): void
    {
        $customer = $this->makeCustomer();
        $token = $this->tokenOf($customer);

        $added = $this->apiCall('POST', $this->v2Front() . '/member/address', $token,
            ['first_name' => 'ZZ Office', 'address1' => '3 ZZ road', 'default' => true])->assertStatus(201)->json('data');

        $this->assertTrue($added['is_default']);
        $this->assertSame((string) $added['id'], (string) $customer->fresh()->address_id);
    }

    public function test_the_default_address_cannot_be_deleted_but_another_one_can(): void
    {
        $customer = $this->makeCustomer();
        $token = $this->tokenOf($customer);
        $default = $this->apiCall('POST', $this->v2Front() . '/member/address', $token,
            ['first_name' => 'ZZ A', 'address1' => 'A road', 'default' => true])->json('data');
        $other = $this->apiCall('POST', $this->v2Front() . '/member/address', $token, ['first_name' => 'ZZ B', 'address1' => 'B road'])->json('data');

        $this->apiCall('DELETE', $this->v2Front() . '/member/address/' . $default['id'], $token)
            ->assertStatus(422)->assertJsonValidationErrors(['address']);
        $this->apiCall('DELETE', $this->v2Front() . '/member/address/' . $other['id'], $token)->assertOk();

        $this->assertNotNull(ShopCustomerAddress::find($default['id']));
        $this->assertNull(ShopCustomerAddress::find($other['id']));
    }

    public function test_another_customers_address_is_not_found(): void
    {
        $owner = $this->makeCustomer();
        $address = $this->apiCall('POST', $this->v2Front() . '/member/address', $this->tokenOf($owner),
            ['first_name' => 'ZZ Own', 'address1' => 'Own road'])->json('data');
        $intruder = $this->tokenOf($this->makeCustomer());

        $this->apiCall('PUT', $this->v2Front() . '/member/address/' . $address['id'], $intruder, ['first_name' => 'X', 'address1' => 'X'])
            ->assertStatus(404);
        $this->apiCall('DELETE', $this->v2Front() . '/member/address/' . $address['id'], $intruder)->assertStatus(404);
        $this->assertSame('Own road', ShopCustomerAddress::find($address['id'])->address1);
    }

    public function test_address_fields_follow_the_store_settings(): void
    {
        $this->setStoreConfig(['customer_phone' => '1']);
        $token = $this->tokenOf($this->makeCustomer());

        $this->apiCall('POST', $this->v2Front() . '/member/address', $token, ['first_name' => 'ZZ', 'address1' => ''])
            ->assertStatus(422)->assertJsonValidationErrors(['address1', 'phone']);
    }
}
