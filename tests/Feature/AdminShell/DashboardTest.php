<?php

namespace GP247\Shop\Tests\Feature\AdminShell;

use GP247\Core\AdminShell\Http\Livewire\Dashboard;
use GP247\Core\Models\AdminHome;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\TestCase;

/**
 * E2E for the admin dashboard's block rendering (ADR-007): the dashboard now
 * renders whatever is configured in admin_home_layout (view/size/sort/status,
 * model AdminHome) instead of a hardcoded layout.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-LW-001, US-UI-009, US-AUI-005, US-admin-shell-system-alerts
 * @aidlc-adr ADR-002, ADR-005, ADR-007
 */
class DashboardTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    /**
     * Isolate assertions from whatever admin_home rows the environment ships
     * with (e.g. the default seeder), so each test only sees rows it creates.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        AdminHome::query()->delete();
    }

    /**
     * @param string $view
     * @param int    $size
     * @param int    $sort
     * @param int    $status
     * @return AdminHome
     */
    private function seedBlock(string $view, int $size = 12, int $sort = 0, int $status = 1): AdminHome
    {
        return AdminHome::create([
            'view' => $view,
            'size' => $size,
            'sort' => $sort,
            'extension' => explode('::', $view)[0],
            'status' => $status,
        ]);
    }

    public function test_disabled_block_is_not_rendered(): void
    {
        $this->asUser(administrator: true);
        $this->seedBlock('gp247-admin::component.home_footer', status: 0);

        Livewire::test(Dashboard::class)->assertDontSee('xl:col-span-12');
    }

    public function test_applies_configured_size_as_column_span(): void
    {
        $this->asUser(administrator: true);
        $this->seedBlock('gp247-admin::component.home_default', size: 6);

        Livewire::test(Dashboard::class)->assertSeeHtml('xl:col-span-6');
    }

    public function test_skips_blocks_whose_view_is_not_registered(): void
    {
        $this->asUser(administrator: true);
        $this->seedBlock('gp247-shop-admin::component.does_not_exist');

        Livewire::test(Dashboard::class)->assertStatus(200);
    }

    public function test_shop_blocks_render_when_shop_package_is_installed(): void
    {
        if (!class_exists(\GP247\Shop\Admin\Models\AdminOrder::class)) {
            $this->markTestSkipped('gp247/shop package is not installed in this app.');
        }

        $this->asUser(administrator: true);
        $this->seedBlock('gp247-shop-admin::component.top_info', size: 12);

        Livewire::test(Dashboard::class)->assertSeeHtml('xl:col-span-12');
    }

    public function test_an_administrator_sees_the_edit_layout_shortcut(): void
    {
        $this->asUser(administrator: true);

        Livewire::test(Dashboard::class)->assertSeeHtml('data-testid="admin-shell-dashboard-edit-layout"');
    }

    public function test_a_user_granted_the_layout_screen_sees_the_edit_layout_shortcut(): void
    {
        $this->asUser(slugs: ['admin_home_layout']);

        Livewire::test(Dashboard::class)->assertSeeHtml('data-testid="admin-shell-dashboard-edit-layout"');
    }

    public function test_a_restricted_user_does_not_see_the_edit_layout_shortcut(): void
    {
        // e.g. a store-admin: may open the dashboard but not the (global) layout screen.
        $this->asUser(slugs: ['admin_order', 'admin_product']);

        Livewire::test(Dashboard::class)->assertDontSeeHtml('admin-shell-dashboard-edit-layout');
    }

    public function test_an_administrator_sees_the_system_alerts_block_on_the_dashboard(): void
    {
        $this->asUser(administrator: true);
        $this->seedBlock('gp247-admin::component.alerts', sort: 1000);

        Livewire::test(Dashboard::class)->assertSeeHtml('admin-alerts');
    }

    public function test_a_store_admin_sees_no_system_alerts_block_on_the_dashboard(): void
    {
        $this->asUser(slugs: ['admin_order', 'admin_product']);
        $this->seedBlock('gp247-admin::component.alerts', sort: 1000);

        Livewire::test(Dashboard::class)->assertDontSeeHtml('admin-alerts');
    }

    public function test_the_upgrade_migration_adds_the_alert_block_once_at_the_top(): void
    {
        $this->seedBlock('gp247-shop-admin::component.top_info', sort: 100);
        $migration = require base_path('vendor/gp247/core/src/Database/Migrations/upgrade/2026_11_04_113754_seed_admin_alert_block.php');

        $migration->up();
        $migration->up();

        $rows = AdminHome::where('view', 'gp247-admin::component.alerts')->get();
        $this->assertCount(1, $rows, 'running the upgrade twice must not add a second block');
        $this->assertSame(12, (int) $rows[0]->size);
        $this->assertSame((int) AdminHome::max('sort'), (int) $rows[0]->sort, 'the alert block sorts above every other block');
    }

    public function test_the_upgrade_migration_keeps_an_alert_block_the_owner_disabled(): void
    {
        $this->seedBlock('gp247-admin::component.alerts', sort: 5, status: 0);
        $migration = require base_path('vendor/gp247/core/src/Database/Migrations/upgrade/2026_11_04_113754_seed_admin_alert_block.php');

        $migration->up();

        $rows = AdminHome::where('view', 'gp247-admin::component.alerts')->get();
        $this->assertCount(1, $rows);
        $this->assertSame(0, (int) $rows[0]->status);
    }
}
