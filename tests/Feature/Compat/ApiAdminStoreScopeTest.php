<?php

namespace GP247\Shop\Tests\Feature\Compat;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * Admin REST API only returns data of the store the token owner works in, resolved by
 * the same admin store resolver as the web, and a tenant plugin can veto the call
 * through the fail-closed api.admin_fence seam.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-admin-store-scope
 * @aidlc-adr compat-foundation_rest-api-hardening
 */
class ApiAdminStoreScopeTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;

    public function test_store_bound_admin_lists_only_orders_of_its_store(): void
    {
        $store = $this->otherStoreId();
        $mine = $this->makeOrder(['store_id' => $store]);
        $foreign = $this->makeOrder();
        $this->pinAdminStoreContext(fn () => $store, fn () => true);
        $admin = $this->makeAdmin(['admin_order.index']);

        $page = $this->getWithToken($this->coreApi() . '/order/list', $this->tokenFor($admin, ['admin']))->assertOk();
        $ids = collect($page->json('data'))->pluck('id');
        $total = \GP247\Shop\Models\ShopOrder::where('store_id', $store)->count();

        $this->assertSame($total, $page->json('total'), 'List total must count only orders of the bound store');
        $this->assertNotContains($foreign->id, $ids->all());
        $this->assertTrue($ids->every(fn ($id) => \GP247\Shop\Models\ShopOrder::find($id)->store_id == $store));
        $this->assertGreaterThanOrEqual(1, $total, "Order {$mine->id} of the bound store must be counted");
    }

    public function test_store_bound_admin_reads_an_order_of_its_own_store(): void
    {
        $store = $this->otherStoreId();
        $mine = $this->makeOrder(['store_id' => $store]);
        $this->pinAdminStoreContext(fn () => $store, fn () => true);
        $admin = $this->makeAdmin(['admin_order.detail']);

        $this->getWithToken($this->coreApi() . '/order/detail/' . $mine->id, $this->tokenFor($admin, ['admin']))
            ->assertOk()
            ->assertJson(['id' => $mine->id]);
    }

    public function test_store_bound_admin_cannot_read_an_order_of_another_store_by_id(): void
    {
        $store = $this->otherStoreId();
        $foreign = $this->makeOrder();
        $this->pinAdminStoreContext(fn () => $store, fn () => true);
        $admin = $this->makeAdmin(['admin_order.detail']);

        $this->getWithToken($this->coreApi() . '/order/detail/' . $foreign->id, $this->tokenFor($admin, ['admin']))
            ->assertJsonMissing(['id' => $foreign->id]);
    }

    public function test_root_admin_still_reads_an_order_of_any_store(): void
    {
        $order = $this->makeOrder(['store_id' => $this->otherStoreId()]);
        $this->pinAdminStoreContext(null, null);
        $admin = $this->makeAdmin(['admin_order.detail']);

        $this->getWithToken($this->coreApi() . '/order/detail/' . $order->id, $this->tokenFor($admin, ['admin']))
            ->assertOk()
            ->assertJson(['id' => $order->id]);
    }

    public function test_fence_rejection_is_forbidden(): void
    {
        $this->pinAdminStoreContext(null, fn () => false);
        $admin = $this->makeAdmin(['admin_order.index']);

        $this->getWithToken($this->coreApi() . '/order/list', $this->tokenFor($admin, ['admin']))
            ->assertStatus(403)
            ->assertJson(['code' => 'forbidden']);
    }

    public function test_fence_error_denies_access(): void
    {
        $this->pinAdminStoreContext(null, function () {
            throw new \RuntimeException('fence down');
        });
        $admin = $this->makeAdmin(['admin_order.index']);

        $this->getWithToken($this->coreApi() . '/order/list', $this->tokenFor($admin, ['admin']))->assertStatus(403);
    }

    public function test_store_resolver_error_denies_access(): void
    {
        $this->pinAdminStoreContext(function () {
            throw new \RuntimeException('resolver down');
        }, fn () => true);
        $admin = $this->makeAdmin(['admin_order.index']);

        $this->getWithToken($this->coreApi() . '/order/list', $this->tokenFor($admin, ['admin']))->assertStatus(403);
    }

    public function test_store_resolver_without_fence_lets_only_administrators_through(): void
    {
        $this->pinAdminStoreContext(fn () => GP247_STORE_ID_ROOT, null);
        $staff = $this->makeAdmin(['admin_order.index']);
        $owner = $this->makeAdmin([], ['administrator']);

        $this->getWithToken($this->coreApi() . '/order/list', $this->tokenFor($staff, ['admin']))->assertStatus(403);
        $this->getWithToken($this->coreApi() . '/order/list', $this->tokenFor($owner, ['admin-supper']))->assertOk();
    }

    public function test_empty_store_answer_denies_a_non_administrator(): void
    {
        $this->pinAdminStoreContext(fn () => null, fn () => true);
        $staff = $this->makeAdmin(['admin_order.index']);

        $this->getWithToken($this->coreApi() . '/order/list', $this->tokenFor($staff, ['admin']))->assertStatus(403);
    }

    public function test_admin_controller_reached_without_the_api_admin_middleware_fails_closed(): void
    {
        $this->expectException(\LogicException::class);

        (new \GP247\Shop\Api\Admin\AdminOrderController())->getOrderList(request());
    }
}
