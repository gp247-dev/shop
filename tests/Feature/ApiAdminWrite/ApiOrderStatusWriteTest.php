<?php

namespace GP247\Shop\Tests\Feature\ApiAdminWrite;

use GP247\Shop\Models\ShopOrderStatus;
use GP247\Shop\Models\ShopProduct;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Group;
use GP247\Shop\Tests\Feature\ApiAdminWrite\Support\OrderWriteFixtures;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\ConfiguresStockPolicy;
use GP247\Shop\Tests\TestCase;

/**
 * An ERP changes an order's status through the admin API with exactly the outcome of
 * the admin order screen — the same status seam (stock back on cancel, history), the
 * same permission check as the screen's own actions, and the account's store only.
 *
 * @aidlc-unit api-admin-write
 * @aidlc-story US-api-admin-write-order-status
 * @aidlc-adr api-admin-write_write-authorization-as-admin-action
 */
#[Group('api-admin-write')]
class ApiOrderStatusWriteTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;
    use OrderWriteFixtures;
    use ConfiguresStockPolicy;

    public function test_cancelling_an_order_through_the_api_returns_its_stock_and_records_the_history_like_the_admin_screen(): void
    {
        [$order, $product] = $this->orderWithStock(98, ['status' => ShopOrderStatus::NEW]);
        $admin = $this->makeAdmin([], ['administrator']);

        $this->write($this->tokenFor($admin, ['admin']), 'order/' . $order->id . '/status', ['status' => ShopOrderStatus::CANCELED])
            ->assertOk()->assertJsonPath('data.id', $order->id)->assertJsonPath('data.status', ShopOrderStatus::CANCELED);

        $this->assertSame(100.0, (float) ShopProduct::find($product->id)->stock);
        $last = $this->historyOf($order)->last();
        $this->assertSame((string) $admin->id, (string) $last->admin_id);
        $this->assertStringContainsString('API', (string) $last->content);
    }

    public function test_setting_the_status_the_order_already_has_changes_nothing(): void
    {
        [$order] = $this->orderWithStock(98, ['status' => ShopOrderStatus::NEW]);
        $before = $this->historyOf($order)->count();

        $this->write($this->writerToken(), 'order/' . $order->id . '/status', ['status' => ShopOrderStatus::NEW])->assertOk();

        $this->assertSame($before, $this->historyOf($order)->count());
    }

    public function test_a_status_that_is_not_in_the_status_list_is_refused(): void
    {
        [$order] = $this->orderWithStock();

        $this->write($this->writerToken(), 'order/' . $order->id . '/status', ['status' => 987654])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->write($this->writerToken(), 'order/' . $order->id . '/status', [])
            ->assertStatus(422);
    }

    public function test_a_finished_order_can_be_reopened_as_on_the_admin_screen(): void
    {
        [$order] = $this->orderWithStock(98, ['status' => ShopOrderStatus::DONE]);

        $this->write($this->writerToken(), 'order/' . $order->id . '/status', ['status' => ShopOrderStatus::NEW])
            ->assertOk()->assertJsonPath('data.status', ShopOrderStatus::NEW);
    }

    public function test_reopening_is_refused_with_the_seams_reason_when_stock_is_short(): void
    {
        $this->setProductStock(true);
        $this->setBuyOutOfStock(false);
        [$order, $product] = $this->orderWithStock(98, ['status' => ShopOrderStatus::NEW]);
        $token = $this->writerToken();
        $this->write($token, 'order/' . $order->id . '/status', ['status' => ShopOrderStatus::CANCELED])->assertOk();
        ShopProduct::whereKey($product->id)->update(['stock' => 0]);

        $this->write($token, 'order/' . $order->id . '/status', ['status' => ShopOrderStatus::NEW])
            ->assertStatus(409)->assertJsonPath('code', 'admin.order.reopen_blocked_stock');
    }

    public function test_an_order_of_another_store_is_not_found(): void
    {
        [$order] = $this->orderWithStock();
        $storeId = $this->otherStoreId();
        $this->pinAdminStoreContext(fn () => $storeId, fn () => true);

        $this->write($this->tokenFor($this->adminWithUris(['ANY::' . $this->orderScreen() . '/*']), ['admin']), 'order/' . $order->id . '/status', ['status' => ShopOrderStatus::CANCELED])
            ->assertStatus(404);
    }

    public function test_an_account_that_may_only_view_orders_cannot_change_them(): void
    {
        [$order] = $this->orderWithStock();

        $readOnly = $this->tokenFor($this->makeAdmin(['admin_order.index']), ['admin']);
        $viewAll = $this->tokenFor($this->makeAdmin([], ['view.all']), ['admin']);

        $this->write($readOnly, 'order/' . $order->id . '/status', ['status' => ShopOrderStatus::CANCELED])->assertStatus(403);
        $this->write($viewAll, 'order/' . $order->id . '/status', ['status' => ShopOrderStatus::CANCELED])->assertStatus(403);
    }

    public function test_write_permissions_granted_as_on_the_admin_screens_are_accepted(): void
    {
        foreach (['ANY::' . $this->orderScreen() . '/*', 'POST::' . $this->orderScreen() . '/*'] as $grant) {
            [$order] = $this->orderWithStock(98, ['status' => ShopOrderStatus::NEW]);
            $token = $this->tokenFor($this->adminWithUris([$grant]), ['admin']);

            $this->write($token, 'order/' . $order->id . '/status', ['status' => ShopOrderStatus::CANCELED])->assertOk();
        }
    }

    /**
     * @return string Token of an administrator.
     */
    private function writerToken(): string
    {
        return $this->tokenFor($this->makeAdmin([], ['administrator']), ['admin']);
    }
}
