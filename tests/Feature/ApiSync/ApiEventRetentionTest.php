<?php

namespace GP247\Shop\Tests\Feature\ApiSync;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Group;
use GP247\Shop\Tests\Feature\ApiSync\Support\EventLogFixtures;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * The event log keeps itself small without a cron: expired events are cleaned in small
 * batches during normal requests, a cursor pointing into the cleaned part is told so,
 * and gp247:doctor flags a log whose cleaning does not run.
 *
 * @aidlc-unit api-sync
 * @aidlc-story US-api-sync-event-retention
 * @aidlc-adr api-sync_event-log-after-commit
 */
#[Group('api-sync')]
class ApiEventRetentionTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;
    use EventLogFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerEventType('zztest.product', 'admin_product.index');
        $this->setEventLog(true);
        Cache::forget('gp247:api-event-prune');
    }

    public function test_expired_events_are_cleaned_during_normal_requests(): void
    {
        $old = [$this->insertEvent('zztest.product', null, now()->subDays(31)), $this->insertEvent('zztest.product', null, now()->subDays(31))];
        $fresh = $this->insertEvent('zztest.product', null, now()->subDay());
        $token = $this->tokenFor($this->makeAdmin(['admin_product.index']), ['admin']);

        $this->apiCall('GET', $this->v2Core() . '/events?after=' . $fresh, $token)->assertOk();

        $this->assertSame(0, $this->events()->whereIn('id', $old)->count());
        $this->assertSame(1, $this->events()->where('id', $fresh)->count());
    }

    public function test_a_cursor_pointing_into_the_cleaned_part_is_told_to_resync(): void
    {
        $old = $this->insertEvent('zztest.product', null, now()->subDays(31));
        $fresh = $this->insertEvent('zztest.product', null, now()->subDay());
        $token = $this->tokenFor($this->makeAdmin(['admin_product.index']), ['admin']);
        $this->apiCall('GET', $this->v2Core() . '/events?after=' . $fresh, $token)->assertOk();

        $this->apiCall('GET', $this->v2Core() . '/events?after=' . ($old - 1), $token)
            ->assertStatus(410)->assertJsonPath('code', 'cursor_expired');
    }

    public function test_doctor_flags_a_log_whose_cleaning_does_not_run(): void
    {
        $this->insertEvent('zztest.product', null, now()->subDays(40));

        Artisan::call('gp247:doctor', ['--json' => true]);
        $json = json_decode(Artisan::output(), true);
        $checks = $json['data']['checks'] ?? $json['error']['data']['checks'] ?? [];
        $check = collect($checks)->firstWhere('name', 'api_event_retention');

        $this->assertNotNull($check, 'doctor must report api_event_retention');
        $this->assertSame('warn', $check['status']);
    }
}
