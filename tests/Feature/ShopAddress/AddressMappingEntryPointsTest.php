<?php

namespace GP247\Shop\Tests\Feature\ShopAddress;

use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Admin\Livewire\ShopConfigForm;
use GP247\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;

/**
 * The address lists screen is reachable without knowing it is a tab: a fixed entry in the
 * shop settings menu, and a link from the Customer tab where the address fields are switched on.
 *
 * @aidlc-unit shop-address
 * @aidlc-story US-shop-address-field-mapping
 */
#[Group('shop-address')]
class AddressMappingEntryPointsTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    private const MIGRATION = __DIR__ . '/../../../src/Admin/Database/Migrations/upgrade/2026_11_24_171107_add_address_mapping_admin_menu.php';

    public function test_an_existing_site_gets_one_menu_entry_under_shop_settings_even_when_updated_twice(): void
    {
        $menu = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_menu');
        (clone $menu)->where('uri', 'admin::address_mapping')->delete();
        $settingsId = (clone $menu)->where('key', 'ADMIN_SHOP_SETTING')->value('id');
        $this->assertNotNull($settingsId, 'the shop settings group exists');

        (require self::MIGRATION)->up();
        (require self::MIGRATION)->up();

        $rows = (clone $menu)->where('uri', 'admin::address_mapping')->get();
        $this->assertCount(1, $rows);
        $this->assertSame((string) $settingsId, (string) $rows[0]->parent_id);
        $this->assertSame('admin.menu_titles.address_mapping', $rows[0]->title);
        $this->assertNotSame('admin.menu_titles.address_mapping', gp247_language_render('admin.menu_titles.address_mapping'));
    }

    public function test_a_fresh_install_seeds_the_same_menu_entry(): void
    {
        $seeder = (string) file_get_contents(__DIR__ . '/../../../src/Admin/Database/Seeders/DataShopInitializeSeeder.php');

        $this->assertMatchesRegularExpression("/'title' => 'admin\\.menu_titles\\.address_mapping'.*'uri' => 'admin::address_mapping'/", $seeder);
    }

    public function test_the_customer_tab_links_to_the_address_lists(): void
    {
        $this->asUser(slugs: ['admin_shop_config']);
        session(['adminStoreId' => (string) GP247_STORE_ID_ROOT]);

        Livewire::test(ShopConfigForm::class)
            ->assertSee('data-testid="shop-admin-config-customer-address-link"', false)
            ->assertSee(gp247_route_admin('admin_address_mapping.index'), false)
            ->assertSee(gp247_language_render('admin.address_mapping.customer_tab_hint'));
    }
}
