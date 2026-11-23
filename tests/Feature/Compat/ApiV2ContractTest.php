<?php

namespace GP247\Shop\Tests\Feature\Compat;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * api/v2 serves the same data as v1 through declared resources: success bodies are
 * {"data":…} (+ "meta"/"links" for lists), the v1 security layers apply unchanged
 * (RBAC, store scope, public field set), logout is POST only, and /meta describes
 * the API to clients.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-v2-contract
 * @aidlc-adr compat-foundation_rest-api-hardening
 */
class ApiV2ContractTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;

    private const CORE = '/api/v2/core';
    private const FRONT = '/api/v2/front';

    public function test_admin_login_returns_the_token_inside_data(): void
    {
        $admin = $this->makeAdmin();

        $this->postJson(self::CORE . '/login', ['username' => $admin->username, 'password' => 'Secret#123'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['access_token', 'token_type', 'scopes', 'expires_at']]);
    }

    public function test_admin_lists_have_data_meta_and_links(): void
    {
        $this->pinAdminStoreContext(null, null);
        $this->makeOrder();
        $token = $this->tokenFor($this->makeAdmin(['admin_order.index']), ['admin']);

        $this->getWithToken(self::CORE . '/order/list', $token)
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'status', 'total', 'currency', 'details']], 'meta' => ['current_page', 'per_page', 'total', 'last_page'], 'links' => ['first', 'last', 'prev', 'next']]);
    }

    public function test_v2_admin_endpoints_enforce_the_same_rbac_and_store_scope(): void
    {
        $store = $this->otherStoreId();
        $foreign = $this->makeOrder();
        $this->pinAdminStoreContext(fn () => $store, fn () => true);

        $this->getWithToken(self::CORE . '/customer/list', $this->tokenFor($this->makeAdmin(['admin_order.index']), ['admin']))
            ->assertStatus(403)->assertJson(['code' => 'forbidden']);
        $this->getWithToken(self::CORE . '/order/detail/' . $foreign->id, $this->tokenFor($this->makeAdmin(['admin_order.detail']), ['admin']))
            ->assertStatus(404)->assertJson(['code' => 'not_found']);
    }

    public function test_public_product_resource_has_no_internal_fields(): void
    {
        $product = $this->makeProduct();

        $data = $this->getJson(self::FRONT . '/product/detail/' . $product->id)->assertOk()->json('data');

        $this->assertSame($product->id, $data['id']);
        $this->assertArrayHasKey('name', $data);
        $this->assertArrayHasKey('price_final', $data);
        foreach (['cost', 'supplier_id', 'stock', 'sold', 'approve'] as $field) {
            $this->assertArrayNotHasKey($field, $data);
        }
    }

    public function test_admin_product_resource_includes_inventory_and_cost(): void
    {
        $this->pinAdminStoreContext(null, null);
        $product = $this->makeProduct();
        $token = $this->tokenFor($this->makeAdmin(['admin_product.index']), ['admin']);

        $data = $this->getWithToken(self::CORE . '/product/detail/' . $product->id, $token)->assertOk()->json('data');

        $this->assertEquals(42, $data['cost']);
        $this->assertEquals(50, $data['stock']);
    }

    public function test_hidden_products_are_not_served_by_v2_either(): void
    {
        $product = $this->makeProduct(['approve' => 0]);

        $this->getJson(self::FRONT . '/product/detail/' . $product->id)->assertStatus(404)->assertJson(['code' => 'not_found']);
    }

    public function test_member_orders_are_served_inside_data_without_forensics(): void
    {
        $customer = $this->makeCustomer();
        $order = $this->makeOrder(['customer_id' => $customer->id, 'ip' => '10.9.9.9']);

        $data = $this->getWithToken(self::FRONT . '/member/order/detail/' . $order->id, $this->tokenFor($customer, ['user']))
            ->assertOk()->json('data');

        $this->assertSame($order->id, $data['id']);
        $this->assertArrayNotHasKey('ip', $data);
    }

    public function test_logout_is_post_only_in_v2(): void
    {
        $token = $this->tokenFor($this->makeAdmin(), ['admin']);

        $this->getWithToken(self::CORE . '/logout', $token)->assertStatus(405)->assertJson(['code' => 'method_not_allowed']);
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer ' . $token)->postJson(self::CORE . '/logout')->assertOk();
    }

    public function test_meta_describes_the_api(): void
    {
        $this->getJson(self::FRONT . '/meta')
            ->assertOk()
            ->assertJson(['data' => ['api_version' => '2']])
            ->assertJsonStructure(['data' => ['api_version', 'packages', 'languages', 'store_id']]);
    }

    public function test_every_v2_admin_data_route_declares_an_admin_screen(): void
    {
        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            // WHY: sign-in and the token owner's own endpoints (info, sessions) need no admin screen.
            $ownerEndpoints = ['login', 'login/challenge', 'refresh', 'logout', 'info', 'sessions', 'sessions/{id}'];
            if (!Str::startsWith($uri, 'api/v2/core/') || in_array(Str::after($uri, 'api/v2/core/'), $ownerEndpoints, true)) {
                continue;
            }
            $declared = collect($route->gatherMiddleware())->first(fn ($mw) => is_string($mw) && Str::startsWith($mw, 'api.admin:'));
            $this->assertNotNull($declared, "v2 admin route {$uri} must declare api.admin:<admin route name>");
            $checked++;
        }
        $this->assertGreaterThanOrEqual(16, $checked);
    }

    public function test_meta_names_packages_without_exact_versions(): void
    {
        $packages = $this->getJson(self::FRONT . '/meta')->assertOk()->json('data.packages');

        $this->assertContains('gp247/core', $packages);
        $this->assertTrue(array_is_list($packages), 'packages is a plain list of names');
    }

    public function test_account_write_endpoints_exist_only_in_v2_and_answer_in_the_data_envelope(): void
    {
        $customer = $this->makeCustomer();
        $token = $this->tokenFor($customer, ['user']);

        $this->apiCall('GET', self::FRONT . '/member/profile', $token)->assertOk()->assertJsonStructure(['data' => ['id', 'email']]);
        $this->apiCall('GET', self::FRONT . '/member/sessions', $token)->assertOk()->assertJsonStructure(['data']);
        foreach (['GET member/profile', 'PUT member/password', 'POST register', 'POST refresh', 'GET member/sessions'] as $endpoint) {
            [$method, $path] = explode(' ', $endpoint);
            $this->apiCall($method, $this->frontApi() . '/' . $path, $token)->assertStatus(404);
        }
    }
}
