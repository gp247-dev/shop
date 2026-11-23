<?php

namespace GP247\Shop\Tests\Feature\ApiSync;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Group;
use GP247\Shop\Tests\Feature\ApiSync\Support\EventLogFixtures;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * An ERP reads the event log by cursor, in the order things happened, seeing only the
 * event types of the admin screens it may open and only its own store.
 *
 * @aidlc-unit api-sync
 * @aidlc-story US-api-sync-event-read
 * @aidlc-adr api-sync_event-log-after-commit
 */
#[Group('api-sync')]
class ApiEventReadTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;
    use EventLogFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerEventType('zztest.product', 'admin_product.index');
        $this->registerEventType('zztest.order', 'admin_order.index');
        $this->setEventLog(true);
    }

    public function test_an_erp_reads_events_after_its_cursor_in_order_and_reading_again_gives_the_same_page(): void
    {
        $token = $this->tokenFor($this->makeAdmin(['admin_product.index']), ['admin']);
        $start = $this->lastEventId();
        $ids = [$this->insertEvent('zztest.product'), $this->insertEvent('zztest.product'), $this->insertEvent('zztest.product')];

        $first = $this->read($token, ['after' => $start, 'page' => ['size' => 2]])->assertOk()->json();
        $again = $this->read($token, ['after' => $start, 'page' => ['size' => 2]])->assertOk()->json();
        $rest = $this->read($token, ['after' => $first['meta']['next_after']])->assertOk()->json();

        $this->assertSame(array_slice($ids, 0, 2), array_column($first['data'], 'id'));
        $this->assertSame($first, $again);
        $this->assertTrue($first['meta']['has_more']);
        $this->assertSame([$ids[2]], array_column($rest['data'], 'id'));
        $this->assertFalse($rest['meta']['has_more']);
        $this->assertSame('zztest.product', $first['data'][0]['type']);
        $this->assertArrayHasKey('source', $first['data'][0]);
    }

    public function test_an_account_sees_only_the_event_types_of_its_screens_and_only_its_store(): void
    {
        $start = $this->lastEventId();
        $storeId = $this->otherStoreId();
        $mine = $this->insertEvent('zztest.product', $storeId);
        $this->insertEvent('zztest.product');
        $this->insertEvent('zztest.order', $storeId);
        $this->pinAdminStoreContext(fn () => $storeId, fn () => true);
        $token = $this->tokenFor($this->makeAdmin(['admin_product.index']), ['admin']);

        $ids = array_column($this->read($token, ['after' => $start])->assertOk()->json('data'), 'id');

        $this->assertSame([$mine], $ids);
    }

    public function test_an_account_with_none_of_the_screens_is_refused(): void
    {
        // WHY brands: the shop registers order, product and customer event types; brands have none.
        $token = $this->tokenFor($this->makeAdmin(['admin_brand.index']), ['admin']);

        $this->read($token, [])->assertStatus(403)->assertJsonPath('code', 'forbidden');
    }

    public function test_events_can_be_filtered_by_type(): void
    {
        $token = $this->tokenFor($this->makeAdmin(['admin_product.index', 'admin_order.index']), ['admin']);
        $start = $this->lastEventId();
        $this->insertEvent('zztest.product');
        $order = $this->insertEvent('zztest.order');

        $ids = array_column($this->read($token, ['after' => $start, 'filter' => ['type' => 'zztest.order']])->assertOk()->json('data'), 'id');

        $this->assertSame([$order], $ids);
    }

    public function test_an_event_younger_than_the_settle_window_is_not_shown_yet(): void
    {
        $token = $this->tokenFor($this->makeAdmin(['admin_product.index']), ['admin']);
        $start = $this->lastEventId();
        $this->insertEvent('zztest.product', null, now());

        $this->assertSame([], $this->read($token, ['after' => $start])->assertOk()->json('data'));
    }

    public function test_reading_while_the_log_is_off_is_refused(): void
    {
        $this->setEventLog(false);
        $token = $this->tokenFor($this->makeAdmin(['admin_product.index']), ['admin']);

        $this->read($token, [])->assertStatus(409)->assertJsonPath('code', 'event_log_disabled');
    }

    /**
     * @param string $token
     * @param array  $query
     * @return \Illuminate\Testing\TestResponse
     */
    private function read(string $token, array $query)
    {
        return $this->apiCall('GET', $this->v2Core() . '/events' . ($query ? '?' . http_build_query($query) : ''), $token);
    }
}
