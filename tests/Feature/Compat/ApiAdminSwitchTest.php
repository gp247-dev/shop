<?php

namespace GP247\Shop\Tests\Feature\Compat;

use GP247\Core\Api\Support\ApiRouteRegistrar;
use GP247\Core\Database\Seeders\DataDefaultSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * The site owner turns the REST API on and off from the API management screen: one
 * master switch plus one per version and audience (v1/v2 × core/front). The switch
 * is a gate in the shared API middleware read on every request, so it works at once
 * even with cached routes; a missing key counts as on; a fresh install ships v1 off
 * while an upgraded site keeps everything on.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-admin-switch
 * @aidlc-adr compat-foundation_rest-api-hardening
 */
class ApiAdminSwitchTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;

    private const MIGRATION = 'vendor/gp247/core/src/Database/Migrations/upgrade/2026_10_27_133824_seed_api_switch_config_and_labels.php';

    private function frontV2(): string
    {
        return '/' . trim(GP247_API_V2_FRONT_PREFIX, '/');
    }

    private function assertApiDisabled($response): void
    {
        $response->assertStatus(404)->assertJson(['error' => 1, 'code' => 'api_disabled']);
    }

    private function assertNotApiDisabled($response): void
    {
        $this->assertNotSame('api_disabled', $response->json('code'), 'Route blocked although its switch is on');
    }

    private function migration(): object
    {
        return require base_path(self::MIGRATION);
    }

    public function test_turning_the_whole_api_off_closes_every_group(): void
    {
        $this->setApiSwitch('api_enabled', false);

        $this->assertApiDisabled($this->getJson($this->frontApi() . '/product/list'));
        $this->assertApiDisabled($this->getJson($this->frontV2() . '/product/list'));
        $this->assertApiDisabled($this->postJson($this->coreApi() . '/login', ['username' => 'zz', 'password' => 'zz']));
    }

    /**
     * A disabled group answers api_disabled before authentication runs: an admin
     * endpoint called without a token gets 404 api_disabled, not 401 (Laravel's
     * middleware priority would otherwise run auth first).
     */
    public function test_a_disabled_group_answers_before_authentication(): void
    {
        $this->setApiSwitch('api_v1_core', false);

        $this->assertApiDisabled($this->getJson($this->coreApi() . '/order/list'));
    }

    public function test_turning_one_group_off_closes_only_that_group(): void
    {
        $this->setApiSwitch('api_v1_front', false);

        $this->assertApiDisabled($this->getJson($this->frontApi() . '/product/list'));
        $this->getJson($this->frontV2() . '/product/list')->assertOk();
        $this->assertNotApiDisabled($this->postJson($this->coreApi() . '/login', ['username' => 'zz', 'password' => 'zz']));
    }

    public function test_a_switch_change_applies_on_the_next_request_without_registering_routes_again(): void
    {
        $this->getJson($this->frontApi() . '/product/list')->assertOk();

        $this->setApiSwitch('api_v1_front', false);
        $this->assertApiDisabled($this->getJson($this->frontApi() . '/product/list'));

        $this->setApiSwitch('api_v1_front', true);
        $this->getJson($this->frontApi() . '/product/list')->assertOk();
    }

    public function test_missing_switch_keys_count_as_on(): void
    {
        DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config')
            ->where('store_id', GP247_STORE_ID_GLOBAL)->whereIn('key', $this->apiSwitchKeys)->delete();
        $this->forgetConfigMemo();

        $this->getJson($this->frontApi() . '/product/list')->assertOk();
        $this->getJson($this->frontV2() . '/product/list')->assertOk();
    }

    public function test_a_plugin_route_with_its_own_prefix_follows_only_the_master_switch(): void
    {
        $file = sys_get_temp_dir() . '/gp247api-' . uniqid() . '.php';
        $uri = 'api/zz-plugin-' . uniqid();
        file_put_contents($file, "<?php\nIlluminate\\Support\\Facades\\Route::get('{$uri}', fn () => ['ok' => 1]);\n");
        try {
            ApiRouteRegistrar::register($file);
            Route::getRoutes()->refreshNameLookups();
        } finally {
            @unlink($file);
        }

        foreach (['api_v1_core', 'api_v1_front', 'api_v2_core', 'api_v2_front'] as $key) {
            $this->setApiSwitch($key, false);
        }
        $this->getJson('/' . $uri)->assertOk();

        $this->setApiSwitch('api_enabled', false);
        $this->assertApiDisabled($this->getJson('/' . $uri));
    }

    public function test_every_route_registered_through_the_seam_carries_the_gate(): void
    {
        $seamRoutes = collect(Route::getRoutes())->filter(fn ($route) => in_array('api.extend', $route->middleware(), true));

        $this->assertNotEmpty($seamRoutes);
        foreach ($seamRoutes as $route) {
            $this->assertContains('api.enabled', $route->middleware(), $route->uri() . ' bypasses the API switch');
        }
    }

    public function test_a_fresh_install_turns_v1_off_and_keeps_v2_on(): void
    {
        $rows = collect((new DataDefaultSeeder())->dataAdminConfig())->keyBy('key');

        $this->assertSame('1', (string) $rows['api_enabled']['value']);
        $this->assertSame('0', (string) $rows['api_v1_core']['value']);
        $this->assertSame('0', (string) $rows['api_v1_front']['value']);
        $this->assertSame('1', (string) $rows['api_v2_core']['value']);
        $this->assertSame('1', (string) $rows['api_v2_front']['value']);
        foreach ($this->apiSwitchKeys as $key) {
            $this->assertSame(0, (int) $rows[$key]['store_id']);
        }
    }

    public function test_the_upgrade_turns_every_switch_on_without_overwriting_a_chosen_value(): void
    {
        $table = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config');
        $table->where('store_id', GP247_STORE_ID_GLOBAL)->whereIn('key', $this->apiSwitchKeys)->delete();
        $this->setApiSwitch('api_v1_core', false);

        $this->migration()->up();
        $this->migration()->up();

        $values = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config')
            ->where('store_id', GP247_STORE_ID_GLOBAL)->whereIn('key', $this->apiSwitchKeys)->pluck('value', 'key')->all();
        $this->assertSame(['api_enabled' => '1', 'api_v1_core' => '0', 'api_v1_front' => '1', 'api_v2_core' => '1', 'api_v2_front' => '1'],
            array_merge(array_fill_keys($this->apiSwitchKeys, null), $values));
    }

    public function test_the_upgrade_delivers_the_api_screen_labels_an_upgraded_site_was_missing(): void
    {
        $languages = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'languages');
        $codes = ['admin.api_connection.apikey_keep_hint', 'admin.api_connection.apikey_once', 'admin.api_connection.switch_all'];
        $languages->whereIn('code', $codes)->delete();

        $this->migration()->up();

        foreach ($codes as $code) {
            foreach (['en', 'vi'] as $location) {
                $this->assertTrue(
                    DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'languages')
                        ->where('code', $code)->where('location', $location)->exists(),
                    "$code/$location missing after upgrade"
                );
            }
        }
    }

    public function test_the_upgrade_leaves_the_api_connection_help_text_alone(): void
    {
        $languages = fn () => DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'languages')
            ->where('code', 'admin.api_connection.api_connection_required_help');
        $languages()->where('location', 'vi')->update(['text' => 'Edited by the site owner']);
        $before = $languages()->pluck('text', 'location')->all();

        $this->migration()->up();

        $this->assertSame($before, $languages()->pluck('text', 'location')->all());
    }
}
