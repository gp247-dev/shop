<?php

namespace GP247\Shop\Tests\Feature\Compat;

use GP247\Core\Models\AdminLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * Every authenticated admin API request leaves one line in the operation log, like the
 * admin screens: refused (403) calls too, secrets masked, response bodies never
 * stored; the site-wide log switch turns it off. A guard checks that every admin API
 * route passes one of the two gates that write the log.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-admin-access-log
 * @aidlc-adr compat-foundation_api-token-rotation
 */
class ApiAdminAccessLogTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;

    /**
     * Routes that keep their own contract and are left out of the sweep. The gp247/mcp endpoint
     * is a bare route outside the API groups: it answers 404 while switched off (so a site does
     * not reveal MCP) and writes one operation-log line per tool call, not per request. Its own
     * test suite checks both.
     */
    private const OWN_CONTRACT_ROUTES = ['gp247_mcp.endpoint'];

    private function logsOf($admin)
    {
        return AdminLog::where('user_id', $admin->id)->orderBy('id')->get();
    }

    public function test_reading_customers_through_the_admin_api_is_logged_once(): void
    {
        $admin = $this->makeAdmin(['admin_customer.index']);
        $token = $this->tokenFor($admin, ['admin']);

        $this->apiCall('GET', $this->v2Core() . '/customer/list', $token)->assertOk();

        $logs = $this->logsOf($admin);
        $this->assertCount(1, $logs);
        $this->assertSame('api/v2/core/customer/list', $logs[0]->path);
        $this->assertSame('GET', $logs[0]->method);
    }

    public function test_a_call_refused_by_rbac_is_still_logged(): void
    {
        $admin = $this->makeAdmin();
        $token = $this->tokenFor($admin, ['admin']);

        $this->apiCall('GET', $this->coreApi() . '/customer/list', $token)->assertStatus(403);

        $this->assertSame(['api/core/customer/list'], $this->logsOf($admin)->pluck('path')->all());
    }

    public function test_token_owner_endpoints_are_logged_and_login_is_not(): void
    {
        $admin = $this->makeAdmin();
        $data = $this->apiCall('POST', $this->v2Core() . '/login', null, ['username' => $admin->username, 'password' => 'Secret#123'])
            ->assertOk()->json('data');
        $this->assertCount(0, $this->logsOf($admin));

        $this->apiCall('GET', $this->v2Core() . '/info', $data['access_token'])->assertOk();

        $this->assertSame(['api/v2/core/info'], $this->logsOf($admin)->pluck('path')->all());
    }

    public function test_secrets_in_the_request_are_masked(): void
    {
        $admin = $this->makeAdmin(['admin_customer.index']);
        $token = $this->tokenFor($admin, ['admin']);

        $this->apiCall('GET', $this->v2Core() . '/customer/list?password=zz-secret-value&q=zz', $token)->assertOk();

        $input = (string) $this->logsOf($admin)->first()->input;
        $this->assertStringNotContainsString('zz-secret-value', $input);
        $this->assertStringContainsString('zz', $input);
    }

    public function test_the_operation_log_switch_also_turns_api_logging_off(): void
    {
        config(['gp247-config.admin.admin_log' => 0]);
        $admin = $this->makeAdmin(['admin_customer.index']);
        $token = $this->tokenFor($admin, ['admin']);

        $this->apiCall('GET', $this->v2Core() . '/customer/list', $token)->assertOk();

        $this->assertCount(0, $this->logsOf($admin));
    }

    public function test_every_admin_api_route_passes_a_logging_gate(): void
    {
        $missing = [];
        foreach (Route::getRoutes() as $route) {
            // WHY middleware() not gatherMiddleware(): the latter builds every controller of the
            // site (plugins included) just to read controller middleware, which the gates are not.
            $middleware = $route->middleware();
            if (!in_array('auth:admin-api', $middleware, true) || in_array($route->getName(), self::OWN_CONTRACT_ROUTES, true)) {
                continue;
            }
            $gated = collect($middleware)->contains(fn ($m) => $m === 'api.admin.active' || str_starts_with((string) $m, 'api.admin:'));
            if (!$gated) {
                $missing[] = implode('|', $route->methods()) . ' ' . $route->uri();
            }
        }

        $this->assertSame([], $missing, 'Admin API routes without api.admin / api.admin.active are not logged');
    }
}
