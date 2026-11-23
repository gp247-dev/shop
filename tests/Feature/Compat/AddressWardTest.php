<?php

/**
 * @aidlc-story US-CMP-address-ward
 */

namespace GP247\Shop\Tests\Feature\Compat;

use GP247\Core\Models\AdminConfig;
use GP247\Shop\Models\ShopOrder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopOrders;
use GP247\Shop\Tests\TestCase;

/**
 * Addresses carry a ward (phường/xã) when the shop turns it on — checkout, account
 * forms and the order keep it — and nothing changes for a shop that leaves it off.
 */
#[Group('compat-foundation')]
class AddressWardTest extends TestCase
{
    use DatabaseTransactions;
    use MakesShopOrders;

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->resetConfigCache();
        parent::tearDown();
    }

    public function test_the_ward_column_exists_on_the_three_address_blocks(): void
    {
        foreach (['shop_order', 'shop_customer', 'shop_customer_address'] as $table) {
            $this->assertTrue(Schema::connection(GP247_DB_CONNECTION)->hasColumn(GP247_DB_PREFIX . $table, 'ward'), $table);
        }
    }

    public function test_with_the_ward_switched_off_checkout_and_account_forms_validate_exactly_as_before(): void
    {
        $this->setWard('0', '0');

        $this->assertArrayNotHasKey('ward', gp247_order_mapping_validate()['validate']);
        $this->assertArrayNotHasKey('ward', gp247_customer_address_mapping(['ward' => 'X'])['dataAddress']);
        $this->assertArrayNotHasKey('ward', gp247_customer_data_insert_mapping($this->raw('X'))['dataInsert']);
    }

    public function test_a_required_ward_is_validated_at_checkout_and_kept_in_the_address_book(): void
    {
        $this->setWard('1', '1');

        $this->assertStringContainsString('required', (string) gp247_order_mapping_validate()['validate']['ward']);
        $this->assertSame('Phường 1', gp247_customer_address_mapping(['ward' => 'Phường 1'])['dataAddress']['ward']);
        $this->assertSame('Phường 1', gp247_customer_data_insert_mapping($this->raw('Phường 1'))['dataInsert']['ward']);
        $this->assertSame('Phường 1', gp247_customer_data_edit_mapping($this->raw('Phường 1'))['dataUpdate']['ward']);

        $this->setWard('1', '0');
        $this->assertStringContainsString('nullable', (string) gp247_order_mapping_validate()['validate']['ward']);
    }

    public function test_the_order_stores_the_ward(): void
    {
        $order = $this->makeOrder(['ward' => 'Phường Bến Nghé']);

        $this->assertSame('Phường Bến Nghé', ShopOrder::find($order->id)->ward);
    }

    /**
     * @param string $ward Ward value.
     * @return array<string, string> Raw customer form with the always-present fields.
     */
    private function raw(string $ward): array
    {
        return ['ward' => $ward, 'first_name' => 'An', 'email' => 'an-ward@example.com', 'password' => 'Secret#123', 'password_confirmation' => 'Secret#123', 'id' => 'ZZ-WARD-CUSTOMER'];
    }

    /**
     * @param string $show     customer_ward.
     * @param string $required customer_ward_required.
     * @return void
     */
    private function setWard(string $show, string $required): void
    {
        $root = (string) config('app.storeId', defined('GP247_STORE_ID_ROOT') ? GP247_STORE_ID_ROOT : '1');
        $global = (string) (defined('GP247_STORE_ID_GLOBAL') ? GP247_STORE_ID_GLOBAL : '0');
        foreach (['customer_ward' => $show, 'customer_ward_required' => $required] as $key => $value) {
            AdminConfig::where('key', $key)->whereIn('store_id', [$global, $root])->update(['value' => $value]);
        }
        $this->resetConfigCache();
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
