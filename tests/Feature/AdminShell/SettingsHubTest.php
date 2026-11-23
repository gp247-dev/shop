<?php

namespace GP247\Shop\Tests\Feature\AdminShell;

use GP247\Core\AdminShell\Http\Livewire\SettingsHub;
use GP247\Core\AdminShell\Support\SettingsHubTabRegistry;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\TestCase;

/**
 * E2E for the tabbed configuration hub (ADR-005): renders the tab nav grouping
 * the related settings screens and is read-gated by `admin_config` (ADR-001).
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-008
 * @aidlc-adr ADR-001, ADR-005
 */
class SettingsHubTest extends TestCase
{
    use InteractsWithAdminUser;

    public function test_renders_config_tabs_for_authorized_user(): void
    {
        $this->asUser(slugs: ['admin_config']);

        Livewire::test(SettingsHub::class)
            ->assertSee(gp247_language_render('admin.cfg_general'))
            ->assertSee(gp247_language_render('admin.cfg_email'))
            ->assertSee(gp247_language_render('admin.cfg_custom'))
            // Nested child (Email tab) renders inline — proves tabs host the screens.
            ->assertSee(gp247_language_render('admin.use_smtp'));
    }

    public function test_denied_without_permission(): void
    {
        $this->asUser(slugs: ['something_else']);

        $this->assertDenied(fn () => Livewire::test(SettingsHub::class));
    }

    /**
     * A package (here: shop) contributes a config tab through SettingsHubTabRegistry;
     * an authorized viewer sees it embedded alongside the core tabs — the hub gathers
     * every configuration screen (US-admin-shell-config-hub-tab-registry).
     *
     * @aidlc-story US-admin-shell-config-hub-tab-registry
     */
    public function test_shows_contributed_shop_tab_when_authorized(): void
    {
        $this->registerShopTab();
        $this->asUser(slugs: ['admin_config', 'admin_shop_config']);

        Livewire::test(SettingsHub::class)
            ->assertOk()
            ->assertSee(gp247_language_render('admin.cfg_general'))
            ->assertSee(gp247_language_render('admin.menu_titles.shop_config'))
            // The embedded shop child actually rendered (its Save carries this testid).
            ->assertSee('shop-admin-config-save', false);
    }

    /**
     * A viewer without the contributed tab's permission must NOT see it, and — crucially
     * — the tab's pane is not embedded, so the hub still renders (the child's mount would
     * otherwise throw and break the page). RISK-SEC-config-rbac-uri-collapse.
     *
     * @aidlc-story US-admin-shell-config-hub-tab-registry
     */
    public function test_hides_contributed_tab_without_its_permission(): void
    {
        $this->registerShopTab();
        $this->asUser(slugs: ['admin_config']); // core config only, no admin_shop_config

        Livewire::test(SettingsHub::class)
            ->assertOk()
            ->assertSee(gp247_language_render('admin.cfg_general'))
            ->assertDontSee(gp247_language_render('admin.menu_titles.shop_config'))
            ->assertDontSee('shop-admin-config-save', false);
    }

    /**
     * Inclusive entry: a role scoped only to the contributed screen (redirected here
     * from its old URL) still enters the hub and sees just that tab — not the core
     * config tabs it has no right to (US-admin-shell-config-hub-tab-registry).
     *
     * @aidlc-story US-admin-shell-config-hub-tab-registry
     */
    public function test_contributed_only_role_enters_and_sees_only_its_tab(): void
    {
        $this->registerShopTab();
        $this->asUser(slugs: ['admin_shop_config']); // no admin_config

        Livewire::test(SettingsHub::class)
            ->assertOk()
            ->assertSee(gp247_language_render('admin.menu_titles.shop_config'))
            ->assertDontSee(gp247_language_render('admin.cfg_general'));
    }

    /**
     * Register the canonical shop tab (idempotent with the provider's own boot-time
     * registration) so these tests are deterministic regardless of install state.
     *
     * @return void
     */
    private function registerShopTab(): void
    {
        SettingsHubTabRegistry::register(
            'shop',
            'admin.menu_titles.shop_config',
            'gp247-shop-admin::shop-config-form',
            'gp247_admin/shop_config',
            10,
        );
    }
}
