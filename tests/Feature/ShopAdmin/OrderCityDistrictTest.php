<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Admin\Livewire\OrderManager;
use GP247\Shop\Models\ShopOrder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopOrders;
use GP247\Shop\Tests\TestCase;

/**
 * Admin order HEADER editing of the new administrative address fields city /
 * district (QĐ-6): the admin always sees and edits the full address block
 * regardless of the storefront customer_city/customer_district toggles, so both
 * columns are nullable in saveOrderInfo (config-independent). Persistence +
 * "empty is allowed" (no regression) are covered here; storefront config gating
 * lives in CheckoutCityDistrictTest.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-CMP-address-city-district-schema
 * @aidlc-adr shop-admin_address-city-district
 */
class OrderCityDistrictTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use MakesShopOrders;

    /**
     * Order fixture with the header fields saveOrderInfo requires non-empty.
     *
     * @param array<string, mixed> $overrides
     * @return ShopOrder
     */
    private function makeEditableOrder(array $overrides = []): ShopOrder
    {
        return $this->makeOrder(array_merge([
            'phone' => '0900000000',
            'address1' => '1 Old Street',
            'country' => 'VN',
        ], $overrides));
    }

    public function test_save_order_info_persists_city_and_district(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $order = $this->makeEditableOrder();

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->set('form.city', 'Ha Noi')
            ->set('form.district', 'Cau Giay')
            ->call('saveOrderInfo')
            ->assertHasNoErrors();

        $fresh = ShopOrder::find($order->id);
        $this->assertSame('Ha Noi', $fresh->city);
        $this->assertSame('Cau Giay', $fresh->district);
    }

    public function test_save_order_info_allows_empty_city_district(): void
    {
        // city/district are nullable for admins irrespective of storefront config
        // (QĐ-6): leaving them empty must not raise validation errors.
        $this->asUser(slugs: ['admin_order']);
        $order = $this->makeEditableOrder();

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->set('form.city', '')
            ->set('form.district', '')
            ->set('form.first_name', 'Kept Name')
            ->call('saveOrderInfo')
            ->assertHasNoErrors(['form.city', 'form.district']);
    }
}
