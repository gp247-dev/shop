<?php

namespace GP247\Shop\Tests\Feature\AdminShell;

use GP247\Core\AdminShell\Http\Livewire\ApiConnectionManager;
use GP247\Core\Models\AdminApiConnection;
use GP247\Core\Models\AdminConfig;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\TestCase;

/**
 * E2E for the API connection screen (ADR-001/005): the modern Livewire port of
 * the legacy AdminApiConnectionController two-column screen — a create/edit form
 * on the left, the api_connection_required global toggle + route list + the
 * connections table on the right. CRUD + toggle are Layer-2 gated.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-005
 * @aidlc-adr ADR-001, ADR-005
 */
class ApiConnectionManagerTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    private function globalStoreId()
    {
        return defined('GP247_STORE_ID_GLOBAL') ? GP247_STORE_ID_GLOBAL : 0;
    }

    private function makeConnection(array $overrides = []): AdminApiConnection
    {
        return AdminApiConnection::create(array_merge([
            'description' => 'ZZ Seed conn',
            'apiconnection' => 'zz_seed_conn',
            'apikey' => 'zzseedkey',
            'expire' => null,
            'status' => 1,
        ], $overrides));
    }

    public function test_lists_existing_connections(): void
    {
        $this->asUser(slugs: ['admin_api_connection']);

        $this->makeConnection(['apiconnection' => 'zz_list_one', 'description' => 'ZZ List One']);

        Livewire::test(ApiConnectionManager::class)->assertSee('ZZ List One');
    }

    public function test_expire_field_uses_tailadmin_datepicker_not_native(): void
    {
        $this->asUser(slugs: ['admin_api_connection']);

        $html = Livewire::test(ApiConnectionManager::class)->html();

        // The expire field is the TailAdmin flatpickr datepicker, not <input type="date">.
        $this->assertStringContainsString('gp247Datepicker(', $html);
        $this->assertStringNotContainsString('type="date"', $html);
    }

    public function test_create_persists_connection(): void
    {
        $this->asUser(slugs: ['admin_api_connection']);

        Livewire::test(ApiConnectionManager::class)
            ->set('description', 'ZZ Created')
            ->set('apiconnection', 'zz_created_conn')
            ->set('apikey', str_repeat('zzcreatedkey', 3))
            ->set('status', true)
            ->call('save');

        $row = AdminApiConnection::where('apiconnection', 'zz_created_conn')->first();
        $this->assertNotNull($row);
        $this->assertSame('ZZ Created', $row->description);
        $this->assertSame(1, (int) $row->status);
    }

    public function test_create_rejects_invalid_connection(): void
    {
        $this->asUser(slugs: ['admin_api_connection']);

        Livewire::test(ApiConnectionManager::class)
            ->set('description', 'Bad')
            ->set('apiconnection', 'Has Spaces!')
            ->call('save')
            ->assertHasErrors('apiconnection');

        $this->assertFalse(AdminApiConnection::where('description', 'Bad')->exists());
    }

    public function test_create_rejects_duplicate_connection(): void
    {
        $this->asUser(slugs: ['admin_api_connection']);

        $this->makeConnection(['apiconnection' => 'zz_dup_conn']);

        Livewire::test(ApiConnectionManager::class)
            ->set('description', 'Dup')
            ->set('apiconnection', 'zz_dup_conn')
            ->call('save')
            ->assertHasErrors('apiconnection');
    }

    public function test_edit_loads_row_into_form(): void
    {
        $this->asUser(slugs: ['admin_api_connection']);

        $row = $this->makeConnection(['apiconnection' => 'zz_edit_conn', 'description' => 'ZZ Edit Me']);

        Livewire::test(ApiConnectionManager::class)
            ->call('editRow', $row->id)
            ->assertSet('editingId', $row->id)
            ->assertSet('description', 'ZZ Edit Me')
            ->assertSet('apiconnection', 'zz_edit_conn');
    }

    public function test_update_persists_changes(): void
    {
        $this->asUser(slugs: ['admin_api_connection']);

        $row = $this->makeConnection(['apiconnection' => 'zz_upd_conn', 'description' => 'Old']);

        Livewire::test(ApiConnectionManager::class)
            ->call('editRow', $row->id)
            ->set('description', 'New Desc')
            ->call('save');

        $this->assertSame('New Desc', AdminApiConnection::find($row->id)->description);
    }

    public function test_delete_removes_row(): void
    {
        $this->asUser(slugs: ['admin_api_connection']);

        $row = $this->makeConnection(['apiconnection' => 'zz_del_conn']);

        Livewire::test(ApiConnectionManager::class)->call('deleteRow', $row->id);

        $this->assertNull(AdminApiConnection::find($row->id));
    }

    public function test_generate_key_fills_apikey(): void
    {
        $this->asUser(slugs: ['admin_api_connection']);

        $component = Livewire::test(ApiConnectionManager::class)->call('generateKey');

        $this->assertNotEmpty($component->get('apikey'));
    }

    public function test_toggle_required_persists_to_global(): void
    {
        $this->asUser(slugs: ['admin_api_connection']);

        Livewire::test(ApiConnectionManager::class)->set('apiConnectionRequired', true);

        $this->assertSame(
            '1',
            AdminConfig::where('key', 'api_connection_required')
                ->where('store_id', $this->globalStoreId())->value('value'),
        );
    }

    public function test_save_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);

        $component = Livewire::test(ApiConnectionManager::class)
            ->set('description', 'Nope')
            ->set('apiconnection', 'zz_nope_conn');

        $this->assertDenied(fn () => $component->call('save'));
    }

    public function test_delete_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);

        $row = $this->makeConnection(['apiconnection' => 'zz_protected_conn']);

        $component = Livewire::test(ApiConnectionManager::class);

        $this->assertDenied(fn () => $component->call('deleteRow', $row->id));
    }

    public function test_toggle_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);

        $component = Livewire::test(ApiConnectionManager::class);

        $this->assertDenied(fn () => $component->set('apiConnectionRequired', true));
    }

    /**
     * The route list shows the v2 API next to v1, each version in its own box
     * (US-CMP-api-v2-contract): a v2 path matches neither v1 prefix.
     */
    public function test_route_list_shows_v1_and_v2_endpoints(): void
    {
        $this->asUser(slugs: ['admin_api_connection']);

        Livewire::test(ApiConnectionManager::class)
            ->assertSee('api/core/order/list')
            ->assertSee('api/front/product/list')
            ->assertSee('api/v2/core/order/list')
            ->assertSee('api/v2/front/product/list')
            ->assertSeeHtml('data-testid="api-connection-routes-v2-core"')
            ->assertSeeHtml('data-testid="api-connection-routes-v2-front"');
    }

    private function switchValue(string $key): ?string
    {
        return AdminConfig::where('key', $key)->where('store_id', $this->globalStoreId())->value('value');
    }

    private function setSwitch(string $key, string $value): void
    {
        AdminConfig::updateOrCreate(
            ['key' => $key, 'store_id' => $this->globalStoreId()],
            ['group' => 'global', 'code' => 'api_config', 'value' => $value, 'sort' => 0, 'detail' => 'admin.api_connection.switch_' . $key]
        );
        foreach (['getAllGlobal' => null, 'getAllConfigOfStore' => []] as $name => $empty) {
            $prop = new \ReflectionProperty(AdminConfig::class, $name);
            $prop->setAccessible(true);
            $prop->setValue(null, $empty);
        }
    }

    /**
     * The screen shows the master switch and the four group switches, and turning
     * one off is stored in the global config (US-CMP-api-admin-switch).
     *
     * @aidlc-story US-CMP-api-admin-switch
     */
    public function test_api_switches_show_and_a_toggle_is_stored_globally(): void
    {
        $this->asUser(slugs: ['admin_api_connection']);
        $this->setSwitch('api_v1_core', '1');

        Livewire::test(ApiConnectionManager::class)
            ->assertSeeHtml('data-testid="api-connection-switch-all"')
            ->assertSeeHtml('data-testid="api-connection-switch-v1-core"')
            ->assertSeeHtml('data-testid="api-connection-switch-v1-front"')
            ->assertSeeHtml('data-testid="api-connection-switch-v2-core"')
            ->assertSeeHtml('data-testid="api-connection-switch-v2-front"')
            ->call('setApiSwitch', 'api_v1_core', false)
            ->assertSet('apiSwitches.api_v1_core', false);

        $this->assertSame('0', $this->switchValue('api_v1_core'));
    }

    /** @aidlc-story US-CMP-api-admin-switch */
    public function test_api_switch_toggle_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);

        $component = Livewire::test(ApiConnectionManager::class);

        $this->assertDenied(fn () => $component->call('setApiSwitch', 'api_enabled', false));
    }

    /** @aidlc-story US-CMP-api-admin-switch */
    public function test_an_unknown_switch_key_is_ignored(): void
    {
        $this->asUser(slugs: ['admin_api_connection']);
        $before = $this->switchValue('api_connection_required');

        Livewire::test(ApiConnectionManager::class)->call('setApiSwitch', 'api_connection_required', true);

        $this->assertSame($before, $this->switchValue('api_connection_required'));
    }

    /** @aidlc-story US-CMP-api-admin-switch */
    public function test_switches_are_locked_when_the_server_turns_the_api_off(): void
    {
        $this->asUser(slugs: ['admin_api_connection']);
        $this->setSwitch('api_enabled', '1');
        config(['gp247-config.env.GP247_API_MODE' => 0]);

        Livewire::test(ApiConnectionManager::class)
            ->assertSeeHtml('data-testid="api-connection-switch-locked"')
            ->call('setApiSwitch', 'api_enabled', false)
            ->assertSet('apiSwitches.api_enabled', true);

        $this->assertSame('1', $this->switchValue('api_enabled'));
    }

    /**
     * Only routes behind the API switch are listed: a route with its own middleware
     * chain (like the MCP plugin) is not controlled by the switches.
     *
     * @aidlc-story US-CMP-api-admin-switch
     */
    public function test_route_list_leaves_out_routes_that_bypass_the_switch(): void
    {
        $this->asUser(slugs: ['admin_api_connection']);
        \Illuminate\Support\Facades\Route::get('api/core/zz-own-chain', fn () => 'ok');
        \Illuminate\Support\Facades\Route::getRoutes()->refreshNameLookups();

        Livewire::test(ApiConnectionManager::class)
            ->assertSee('api/core/order/list')
            ->assertDontSee('api/core/zz-own-chain');
    }

    /**
     * The screen links the API guide in the admin's language (gp247.net), opened in a
     * new tab without handing over the window; no OpenAPI link (US-CMP-api-docs-link-from-admin).
     *
     * @aidlc-story US-CMP-api-docs-link-from-admin
     */
    public function test_docs_links_follow_the_admin_language(): void
    {
        $this->asUser(slugs: ['admin_api_connection']);
        app()->setLocale('vi');

        $html = Livewire::test(ApiConnectionManager::class)->html();

        $this->assertStringContainsString('data-testid="api-connection-docs-link"', $html);
        $this->assertStringContainsString('https://gp247.net/vi/docs/api/about-api-gp247.html', $html);
        $this->assertStringNotContainsString('openapi.json', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
    }
}
