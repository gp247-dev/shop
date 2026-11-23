<?php

namespace GP247\Shop\Tests\Feature\ApiAdminWrite;

use GP247\Shop\Models\ShopCustomer;
use GP247\Shop\Models\ShopProduct;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Group;
use GP247\Shop\Tests\Feature\ApiAdminWrite\Support\OrderWriteFixtures;
use GP247\Shop\Tests\Feature\ApiAdminWrite\Support\ShopEventFixtures;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * An ERP keeps orders, products, stock and customers in step with the site by reading
 * only what changed — "changed since" lists plus the event log — and misses nothing,
 * whether the change came from the API, a plugin or a deletion (MC-api-sync-no-full-scan).
 *
 * @aidlc-unit api-admin-write
 * @aidlc-story US-api-admin-write-shop-events
 * @aidlc-adr api-admin-write_model-hook-event-emission
 */
#[Group('api-admin-write')]
class ApiSyncScenarioTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;
    use OrderWriteFixtures;
    use ShopEventFixtures;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_erp_reading_only_what_changed_since_its_last_sync_misses_nothing(): void
    {
        // Everything before the last sync, and the changes after it, sit in the future so no other row of the site interferes.
        $lastSync = Carbon::now()->addDays(3)->startOfMinute();
        Carbon::setTestNow($lastSync->copy()->subHour());
        $untouched = $this->makeProduct();
        $stocked = $this->makeProduct(['stock' => 10]);
        $repriced = $this->makeProduct(['price' => 100]);
        $deleted = $this->makeProduct();
        $customer = $this->makeCustomer();
        $order = $this->makeOrder(['status' => 1]);
        $erp = $this->tokenFor($this->makeAdmin([], ['administrator']), ['admin']);
        $cursor = $this->startEventLog();
        config(['gp247-config.api.event_settle_seconds' => 0]);

        Carbon::setTestNow($lastSync->copy()->addMinutes(5));
        $plugin = ShopProduct::find($stocked->id);
        $plugin->stock += 5;
        $plugin->save();
        $this->apiCall('PATCH', $this->v2Core() . '/product/' . $repriced->id, $erp, ['price' => 120])->assertOk();
        ShopProduct::find($deleted->id)->delete();
        ShopCustomer::find($customer->id)->update(['phone' => '0900000001']);
        $this->write($erp, 'order/' . $order->id . '/status', ['status' => 2])->assertOk();

        $since = urlencode($lastSync->toIso8601String());
        $products = $this->ids($erp, '/product/list?updated_since=' . $since);
        $events = $this->getWithToken($this->v2Core() . '/events?after=' . $cursor, $erp)->assertOk()->json('data');

        sort($products);
        $expected = [(string) $stocked->id, (string) $repriced->id];
        sort($expected);
        $this->assertSame($expected, $products, 'only the changed products are read again');
        $this->assertNotContains((string) $untouched->id, $products);
        $this->assertSame([(string) $customer->id], $this->ids($erp, '/customer/list?updated_since=' . $since));
        $this->assertSame([(string) $order->id], $this->ids($erp, '/order/list?updated_since=' . $since));

        $seen = array_map(fn ($e) => $e['type'] . ' ' . $e['subject_id'], $events);
        foreach (['stock.changed ' . $stocked->id, 'product.updated ' . $repriced->id, 'product.deleted ' . $deleted->id,
            'customer.updated ' . $customer->id, 'order.status_changed ' . $order->id] as $change) {
            $this->assertContains($change, $seen);
        }
    }

    /**
     * Ids of the first page of an admin list.
     *
     * @param string $token
     * @param string $path After api/v2/core.
     * @return string[]
     */
    private function ids(string $token, string $path): array
    {
        return array_map('strval', array_column($this->getWithToken($this->v2Core() . $path, $token)->assertOk()->json('data'), 'id'));
    }
}
