<?php

namespace GP247\Shop\Tests\Feature\ShopAddress;

use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Admin\Livewire\OrderManager;
use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Tests\Feature\ShopAddress\Support\ConfiguresAddressFields;
use GP247\Shop\Tests\Feature\ShopAddress\Support\FakeAddressProvider;
use GP247\Shop\Tests\Feature\ShopAddress\Support\FakeAddressRegistry;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopOrders;
use GP247\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;

/**
 * An administrator correcting the address of an order gets the same server check as a
 * shopper: the plugin's names and codes are stored, a value outside its list is refused.
 *
 * @aidlc-unit shop-address
 * @aidlc-story US-shop-address-server-normalize
 * @aidlc-story US-shop-address-structured-meta
 * @aidlc-story US-SADM-address-city-district
 */
#[Group('shop-address')]
class AddressAdminOrderTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use MakesShopOrders;
    use ConfiguresAddressFields;

    protected function setUp(): void
    {
        parent::setUp();
        FakeAddressRegistry::bind(['FakeAddress' => new FakeAddressProvider()]);
        $this->configureAddress(['customer_city' => '1', 'customer_ward' => '1', 'customer_country' => '1']);
        $this->asUser(slugs: ['admin_order']);
    }

    public function test_correcting_an_order_address_stores_the_plugin_names_and_codes(): void
    {
        $order = $this->makeOrder(['phone' => '0900000000', 'address1' => '1 Old Street', 'country' => 'VN']);

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->set('form.city', 'ha noi')->set('addressCodes.city', '01')
            ->set('form.ward', 'x')->set('addressCodes.ward', '00008')
            ->call('saveOrderInfo')
            ->assertHasNoErrors();

        $fresh = ShopOrder::find($order->id);
        $this->assertSame('Hà Nội', $fresh->city);
        $this->assertSame('Phường Ngọc Hà', $fresh->ward);
        $this->assertSame('00008', $fresh->address_meta['components']['ward']['code']);
    }

    public function test_a_ward_outside_the_selected_province_is_refused_and_the_order_is_left_as_it_was(): void
    {
        $order = $this->makeOrder(['phone' => '0900000000', 'address1' => '1 Old Street', 'country' => 'VN', 'city' => 'Old City']);

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->set('form.city', 'Hà Nội')->set('addressCodes.city', '01')
            ->set('form.ward', 'Phường Sài Gòn')->set('addressCodes.ward', '26734')
            ->call('saveOrderInfo')
            ->assertHasErrors(['form.ward']);

        $fresh = ShopOrder::find($order->id);
        $this->assertSame('Old City', $fresh->city);
        $this->assertNull($fresh->address_meta);
    }

    public function test_an_order_with_a_free_text_address_can_still_be_edited_without_touching_the_address(): void
    {
        $order = $this->makeOrder(['phone' => '0900000000', 'address1' => '1 Old Street', 'country' => 'VN', 'city' => 'Old City']);

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->set('form.comment', 'call before delivery')
            ->call('saveOrderInfo')
            ->assertHasNoErrors();

        $fresh = ShopOrder::find($order->id);
        $this->assertSame('call before delivery', $fresh->comment);
        $this->assertSame('Old City', $fresh->city);
    }

    public function test_the_create_order_screen_binds_the_shared_block_to_its_own_form_state(): void
    {
        // WHY a real session: this screen is a plain controller page behind the admin middleware.
        $user = \GP247\Core\Models\AdminUser::create([
            'id' => 'AU-ADRB-' . substr(uniqid(), -6), 'username' => 'address_block_' . substr(uniqid(), -6),
            'password' => bcrypt('secret'), 'name' => 'Address Block', 'email' => 'block' . substr(uniqid(), -6) . '@example.com', 'status' => 1,
        ]);
        $user->roles()->syncWithoutDetaching([\GP247\Core\Models\AdminRole::firstOrCreate(['slug' => 'administrator'], ['name' => 'Administrator'])->id]);
        $this->actingAs($user, 'admin');
        session(['adminStoreId' => $this->rootStore()]);

        $html = $this->get(route('admin_order.create'))->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="shop-admin-order-create-block"', $html);
        $this->assertStringContainsString('data-testid="shop-admin-order-create-city-select"', $html);
        $this->assertMatchesRegularExpression('/model(\\\\u0022|&quot;|")\s*:\s*(\\\\u0022|&quot;|")fields/', $html);
        $this->assertStringContainsString('name="city_code"', $html);
    }

    public function test_the_order_detail_form_shows_the_shared_block_with_its_test_ids(): void
    {
        $order = $this->makeOrder(['phone' => '0900000000', 'address1' => '1 Old Street', 'country' => 'VN', 'city' => 'Hà Nội']);

        $html = Livewire::test(OrderManager::class, ['id' => (string) $order->id])->html();

        foreach (['city', 'ward', 'address1', 'country'] as $field) {
            $this->assertStringContainsString('data-testid="shop-admin-order-info-' . $field . '"', $html, $field);
        }
        $this->assertStringContainsString('data-testid="shop-admin-order-info-ward-select"', $html);
    }

    public function test_saving_without_touching_the_address_keeps_the_codes_of_the_order(): void
    {
        $meta = ['provider' => 'FakeAddress', 'country' => 'VN', 'data_version' => '2025-07',
            'components' => ['province' => ['code' => '01', 'name' => 'Hà Nội']]];
        $order = $this->makeOrder(['phone' => '0900000000', 'address1' => '1 Old Street', 'country' => 'VN',
            'city' => 'Hà Nội', 'address_meta' => $meta]);

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->set('form.first_name', 'Renamed')
            ->call('saveOrderInfo')
            ->assertHasNoErrors();

        $this->assertSame($meta, ShopOrder::find($order->id)->address_meta);
    }
}
