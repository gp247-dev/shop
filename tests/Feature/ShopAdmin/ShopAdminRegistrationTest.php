<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Core\Models\Languages;
use GP247\Shop\Admin\Database\Seeders\DataShopInitializeSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use GP247\Shop\Tests\TestCase;

/**
 * Proves the shop-admin Unit plugs into the core admin shell via the shared core
 * registrar: routes are registered under the admin prefix, the view namespace
 * resolves, and the menu/lang seeders are idempotent.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-007
 * @aidlc-adr ADR-006, ADR-007
 */
class ShopAdminRegistrationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_shop_admin_screens_are_served_at_legacy_urls(): void
    {
        // Cutover (PA-1): legacy URLs now resolve to the modern Livewire screens.
        $this->assertTrue(Route::has('admin_brand.index'));
        $this->assertTrue(Route::has('admin_brand.edit'));
        $this->assertTrue(Route::has('admin_supplier.index'));
        $this->assertTrue(Route::has('admin_supplier.edit'));
        $this->assertTrue(Route::has('admin_tax.index'));
        $this->assertTrue(Route::has('admin_tax.edit'));
        $this->assertTrue(Route::has('admin_currency.index'));
        $this->assertTrue(Route::has('admin_currency.edit'));
        $this->assertTrue(Route::has('admin_shop_config.index'));

        // Two-panel managers have no standalone create route (inline form).
        $this->assertFalse(Route::has('gp247.shop-admin.brand.create'));
    }

    public function test_component_view_namespace_is_registered(): void
    {
        $this->assertTrue(view()->exists('gp247-shop-admin::brand-manager'));
        $this->assertTrue(view()->exists('gp247-shop-admin::supplier-manager'));
        $this->assertTrue(view()->exists('gp247-shop-admin::tax-manager'));
        $this->assertTrue(view()->exists('gp247-shop-admin::currency-manager'));
        $this->assertTrue(view()->exists('gp247-shop-admin::config-form'));
    }

    public function test_lang_seeder_seeds_en_and_vi_idempotently(): void
    {
        // ShopAdminLangSeeder was merged into DataShopInitializeSeeder (2026-07-01).
        (new DataShopInitializeSeeder())->run();
        (new DataShopInitializeSeeder())->run();

        $this->assertSame(1, Languages::where('code', 'admin.brand.title')->where('location', 'en')->count());
        $this->assertSame(1, Languages::where('code', 'admin.brand.title')->where('location', 'vi')->count());
        $this->assertSame(1, Languages::where('code', 'admin.supplier.title')->where('location', 'vi')->count());
    }
}
