<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Core\AdminShell\Support\SettingsHubTabRegistry;
use GP247\Core\Models\AdminConfig;
use GP247\Shop\Admin\Livewire\ShopConfigForm;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\ConfiguresQtyDecimal;
use GP247\Shop\Tests\TestCase;

/**
 * E2E for the shop-admin configuration screen (ADR-006/007): the tabbed config
 * form loads the legacy config groups, and a single Save writes each key back
 * through AdminConfig (the same scope rules as the core config controllers).
 * Layer-2 authorization gates the save. Config keys unchanged (MC-008). Gated by
 * `admin_shop_config`.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-005
 * @aidlc-adr ADR-001, ADR-006, ADR-007
 */
class ShopConfigTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use ConfiguresQtyDecimal;

    /** Store id the screen targets for store-scoped keys (no session in tests). */
    private function storeId(): int
    {
        return defined('GP247_STORE_ID_ROOT') ? (int) GP247_STORE_ID_ROOT : 1;
    }

    /**
     * Seed a store-scoped display_config row so it surfaces in the "limit" tab.
     */
    private function makeDisplayConfig(string $key, $value): AdminConfig
    {
        return AdminConfig::create([
            'group' => 'shop',
            'code' => 'display_config',
            'key' => $key,
            'value' => $value,
            'store_id' => $this->storeId(),
            'sort' => 0,
            'detail' => 'admin.sort',
        ]);
    }

    public function test_renders_config_screen(): void
    {
        $this->asUser(slugs: ['admin_shop_config']);

        Livewire::test(ShopConfigForm::class)
            ->assertOk()
            ->assertSee(gp247_language_render('admin.save'));
    }

    public function test_save_persists_a_store_config_value(): void
    {
        $this->asUser(slugs: ['admin_shop_config']);
        $this->makeDisplayConfig('zz_test_per_page', '5');

        Livewire::test(ShopConfigForm::class)
            ->assertSet('values.zz_test_per_page', '5')
            ->set('values.zz_test_per_page', 9)
            ->call('save')
            ->assertHasNoErrors();

        $stored = AdminConfig::where('key', 'zz_test_per_page')
            ->where('store_id', $this->storeId())
            ->value('value');
        $this->assertSame('9', (string) $stored);
    }

    /**
     * The "Product" tab is data-driven from `product_config` rows (buildTabs()) —
     * seeding `product_qty_decimal` (ADR-016) must
     * surface it automatically as a checkbox, with no ShopConfigForm.php change.
     */
    public function test_product_tab_surfaces_qty_decimal_toggle_once_seeded(): void
    {
        $this->asUser(slugs: ['admin_shop_config']);
        $this->setQtyDecimalEnabled(false);

        Livewire::test(ShopConfigForm::class)
            ->assertSet('values.product_qty_decimal', 0)
            ->assertSee(gp247_language_render('admin.product.config_manager.product_qty_decimal'));
    }

    /**
     * The Customer tab no longer has a left "basic" column —
     * customer_config flags (e.g. verify-email) move to the top of the right config
     * table, and a customer row whose DB `detail` is empty falls back to its canonical
     * `admin.customer.config_manager.<name>` lang code (fixes the blank "company" row
     * on older installs) instead of rendering blank.
     */
    public function test_customer_tab_moves_basic_flags_into_table_and_labels_blank_rows(): void
    {
        $global = defined('GP247_STORE_ID_GLOBAL') ? GP247_STORE_ID_GLOBAL : 0;
        AdminConfig::updateOrCreate(
            ['key' => 'zz_verify_test', 'store_id' => $global],
            ['group' => 'global', 'code' => 'customer_config', 'value' => '0', 'sort' => 0, 'detail' => 'admin.sort'],
        );
        // Force the real customer_company row to a blank detail (the older-install state
        // this fix targets); DatabaseTransactions rolls the change back after the test.
        AdminConfig::updateOrCreate(
            ['key' => 'customer_company', 'store_id' => $global],
            ['group' => 'global', 'code' => 'customer_config_attribute', 'value' => '0', 'sort' => 0, 'detail' => ''],
        );

        $m = new \ReflectionMethod(ShopConfigForm::class, 'buildTabs');
        $m->setAccessible(true);
        $tabs = collect($m->invoke(new ShopConfigForm))->keyBy('id');
        $customer = collect($tabs['customer']['fields']);

        $this->assertTrue(
            $customer->every(fn ($f) => ($f['section'] ?? '') !== 'basic'),
            'customer tab has no left/basic column',
        );
        $verify = $customer->firstWhere('key', 'zz_verify_test');
        $this->assertSame('attribute', $verify['section'] ?? null, 'customer_config flag moved into the right table');
        $blank = $customer->firstWhere('key', 'customer_company');
        $this->assertSame(
            'admin.customer.config_manager.company',
            $blank['label'],
            'empty-detail customer row falls back to its canonical config_manager lang code, not blank',
        );
    }

    /**
     * When embedded as a tab on the core Configuration hub (store_config), the
     * component must still authorize against its own screen path (shop_config), never
     * the host's — so its screenUri is pinned explicitly (RISK-SEC-config-rbac-uri-collapse).
     *
     * @aidlc-story US-SADM-shop-config-into-hub
     */
    public function test_screen_uri_is_pinned_to_shop_config_path(): void
    {
        $this->asUser(slugs: ['admin_shop_config']);

        $component = new ShopConfigForm();
        $component->mount();

        $prop = new \ReflectionProperty(ShopConfigForm::class, 'screenUri');
        $prop->setAccessible(true);

        $prefix = defined('GP247_ADMIN_PREFIX') ? GP247_ADMIN_PREFIX : 'gp247_admin';
        $this->assertSame($prefix . '/shop_config', $prop->getValue($component));
    }

    /**
     * The shop service provider contributes its config tab to the core hub registry
     * when the shop is installed at DB level (US-SADM-shop-config-into-hub).
     *
     * @aidlc-story US-SADM-shop-config-into-hub
     */
    public function test_shop_registers_its_config_tab_when_installed(): void
    {
        if (!function_exists('gp247_shop_installed') || !gp247_shop_installed()) {
            $this->markTestSkipped('shop not installed in this environment');
        }

        $keys = array_column(SettingsHubTabRegistry::all(), 'key');
        $this->assertContains('shop', $keys, 'shop contributes a config tab at boot when installed');

        $shop = collect(SettingsHubTabRegistry::all())->firstWhere('key', 'shop');
        $this->assertSame('gp247-shop-admin::shop-config-form', $shop['component']);
        $this->assertSame('gp247_admin/shop_config', $shop['authUri']);
    }

    /** GLOBAL store id (base tier for per-store shop config). */
    private function globalId(): int
    {
        return defined('GP247_STORE_ID_GLOBAL') ? (int) GP247_STORE_ID_GLOBAL : 0;
    }

    /**
     * Root at store_config (no bound store, no override) edits the GLOBAL base of a
     * per-store customer config key — same as before, so single-store parity holds.
     *
     * @aidlc-story US-SADM-shop-config-scope-levels
     */
    public function test_root_edits_customer_config_at_global_base(): void
    {
        $this->asUser(slugs: ['admin_shop_config']);
        session()->forget('adminStoreId');
        AdminConfig::updateOrCreate(
            ['key' => 'zz_cust_base', 'store_id' => $this->globalId()],
            ['group' => 'gp247_cart', 'code' => 'customer_config', 'value' => '0', 'sort' => 0, 'detail' => 'admin.sort'],
        );

        Livewire::test(ShopConfigForm::class)
            ->set('values.zz_cust_base', 1)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('1', (string) AdminConfig::where('key', 'zz_cust_base')->where('store_id', $this->globalId())->value('value'));
    }

    /**
     * A bound store-admin editing a per-store customer config key creates a lazy
     * override row for its own store, leaving the GLOBAL base untouched.
     *
     * @aidlc-story US-SADM-shop-config-scope-levels
     */
    public function test_store_admin_creates_customer_config_override_for_its_store(): void
    {
        $this->asUser(slugs: ['admin_shop_config']);
        session(['adminStoreId' => '7']);
        AdminConfig::updateOrCreate(
            ['key' => 'zz_cust_ovr', 'store_id' => $this->globalId()],
            ['group' => 'gp247_cart', 'code' => 'customer_config', 'value' => '0', 'sort' => 0, 'detail' => 'admin.sort'],
        );

        Livewire::test(ShopConfigForm::class)
            ->set('values.zz_cust_ovr', 1)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('1', (string) AdminConfig::where('key', 'zz_cust_ovr')->where('store_id', '7')->value('value'), 'override row created for the bound store');
        $this->assertSame('0', (string) AdminConfig::where('key', 'zz_cust_ovr')->where('store_id', $this->globalId())->value('value'), 'GLOBAL base is not mutated');
    }

    /**
     * The product tab (GLOBAL catalog schema) is hidden whenever the form edits a
     * specific store's scope — a bound store-admin, or a root embed with an override.
     *
     * @aidlc-story US-SADM-shop-config-scope-levels
     */
    public function test_product_tab_hidden_in_store_scope_visible_for_root(): void
    {
        $m = new \ReflectionMethod(ShopConfigForm::class, 'buildTabs');
        $m->setAccessible(true);

        // Root (no bound store) sees the product tab.
        session()->forget('adminStoreId');
        $rootTabs = collect($m->invoke(new ShopConfigForm))->pluck('id');
        $this->assertTrue($rootTabs->contains('product'), 'root sees global product tab');
        $this->assertTrue($rootTabs->contains('customer'));

        // Bound store-admin does not.
        session(['adminStoreId' => '7']);
        $storeTabs = collect($m->invoke(new ShopConfigForm))->pluck('id');
        $this->assertFalse($storeTabs->contains('product'), 'store-admin cannot edit global product config');
        $this->assertTrue($storeTabs->contains('customer'));

        // Root embed with an override also hides it.
        session()->forget('adminStoreId');
        $embed = new ShopConfigForm();
        $embed->scopeStoreIdOverride = '9';
        $embedTabs = collect($m->invoke($embed))->pluck('id');
        $this->assertFalse($embedTabs->contains('product'), 'per-store embed hides global product config');
    }

    public function test_save_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);
        $this->makeDisplayConfig('zz_test_deny', '5');

        $component = Livewire::test(ShopConfigForm::class)
            ->set('values.zz_test_deny', 9);

        $this->assertDenied(fn () => $component->call('save'));

        $stored = AdminConfig::where('key', 'zz_test_deny')
            ->where('store_id', $this->storeId())
            ->value('value');
        $this->assertSame('5', (string) $stored);
    }
}
