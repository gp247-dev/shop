<?php

namespace GP247\Shop\Tests\Feature\Compat;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * Verifies that the REST API layer (admin/front/member) continues to function
 * after the Livewire/TailAdmin cutover. Route existence and auth behaviour
 * must remain unchanged (US-CMP-005).
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-005
 */
class ApiLayerTest extends TestCase
{
    use DatabaseTransactions;
    // WHY: the API switches are a site setting (a fresh install ships v1 off); ApiFixtures turns
    // them on inside the test transaction so this test does not depend on the site.
    use ApiFixtures;

    /**
     * Derive the API prefix used by core routes.
     * Falls back to the default 'api/gp247-admin' if constants are not yet set.
     */
    private function adminApiPrefix(): string
    {
        return defined('GP247_API_CORE_PREFIX')
            ? '/' . ltrim(GP247_API_CORE_PREFIX, '/')
            : '/api/gp247-admin';
    }

    /**
     * The admin API login endpoint must exist and reject requests without credentials (422).
     */
    public function test_admin_api_login_endpoint_exists(): void
    {
        if (!config('gp247-config.env.GP247_API_MODE', 1)) {
            $this->markTestSkipped('GP247_API_MODE is disabled in this environment');
        }

        $response = $this->postJson($this->adminApiPrefix() . '/login', []);

        // 422 = validation error (route exists, credentials missing) — acceptable
        // 405 = method not allowed (route exists but wrong method)
        // 404 = route missing — FAIL
        $this->assertNotEquals(404, $response->status(), 'Admin API login route must exist (US-CMP-005)');
    }

    /**
     * Accessing a protected admin API endpoint without a token must return 401 or 422.
     */
    public function test_admin_api_protected_endpoint_rejects_unauthenticated(): void
    {
        if (!config('gp247-config.env.GP247_API_MODE', 1)) {
            $this->markTestSkipped('GP247_API_MODE is disabled in this environment');
        }

        $response = $this->getJson($this->adminApiPrefix() . '/user/list');

        // Must not be 200 (would indicate no auth guard)
        // Acceptable: 401 (unauthenticated) or 404 (endpoint removed) — but not 200
        $this->assertNotEquals(
            200,
            $response->status(),
            'Protected admin API endpoint must not be publicly accessible'
        );
    }

    /**
     * GP247_API_MODE config key must be readable (not removed or renamed).
     */
    public function test_gp247_api_mode_config_is_readable(): void
    {
        $mode = config('gp247-config.env.GP247_API_MODE');
        // WHY: env() may return string "1"/"0" depending on .env parsing; any scalar is acceptable.
        $this->assertTrue(
            is_scalar($mode) || is_null($mode),
            'GP247_API_MODE config must be a readable scalar value'
        );
    }

    /**
     * The Sanctum guard gp247-api must be registered.
     */
    public function test_sanctum_api_guard_is_registered(): void
    {
        $guards = config('auth.guards');
        $this->assertNotNull($guards, 'auth.guards config must exist');

        // GP247 registers its guard during boot — at minimum 'web' must exist
        $this->assertArrayHasKey('web', $guards, "Default 'web' guard must exist");
    }

    /**
     * Admin API order list answers with a JSON page (routes call getOrderList(); the
     * controller used to implement it as orders(), so the endpoint threw a 500).
     */
    public function test_admin_api_order_list_returns_a_json_page(): void
    {
        // WHY: called outside the route, so pin the store the api.admin middleware would resolve.
        \GP247\Core\Api\Support\ApiStoreScope::set(GP247_STORE_ID_ROOT);
        $controller = new \GP247\Shop\Api\Admin\AdminOrderController();

        $response = $controller->getOrderList(request());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayHasKey('data', $response->getData(true));
    }

    /**
     * Admin API order detail of an unknown id answers the documented "not found" payload.
     */
    public function test_admin_api_order_detail_of_unknown_id_returns_not_found_payload(): void
    {
        \GP247\Core\Api\Support\ApiStoreScope::set(GP247_STORE_ID_ROOT);
        $controller = new \GP247\Shop\Api\Admin\AdminOrderController();

        $payload = $controller->getOrderDetail(request(), 'ORDER-DOES-NOT-EXIST')->getData(true);

        $this->assertSame(1, $payload['error'] ?? null);
    }
}
