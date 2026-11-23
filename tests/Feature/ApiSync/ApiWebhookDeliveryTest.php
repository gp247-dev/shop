<?php

namespace GP247\Shop\Tests\Feature\ApiSync;

use GP247\Core\Api\Sync\ApiEvents;
use GP247\Core\Api\Sync\WebhookDispatcher;
use GP247\Core\Api\Sync\WebhookSignature;
use GP247\Core\Models\AdminApiWebhook;
use GP247\Core\Models\AdminApiWebhookDelivery;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use GP247\Shop\Tests\Feature\ApiSync\Support\EventLogFixtures;
use GP247\Shop\Tests\Feature\ApiSync\Support\WebhookFixtures;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * An ERP receives each event it follows at its webhook URL, signed so it can trust it,
 * without slowing the site: sends run after the response, at most five within two
 * seconds per request, failures are retried later and given up after six attempts, a
 * webhook failing on and on is turned off, and nothing is ever sent inside the network.
 *
 * @aidlc-unit api-sync
 * @aidlc-story US-api-sync-webhook-delivery
 * @aidlc-adr api-sync_webhook-deferred-delivery
 */
#[Group('api-sync')]
class ApiWebhookDeliveryTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;
    use EventLogFixtures;
    use WebhookFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerEventType('zztest.changed', 'admin_product.index', ['status']);
        $this->registerEventType('zztest.other', 'admin_product.index', ['status']);
        $this->setEventLog(true);
        $this->useFakeDns();
        config(['gp247-config.api.event_settle_seconds' => 0]);
    }

    protected function tearDown(): void
    {
        $this->forgetFakeDns();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_followed_event_reaches_the_erp_with_the_same_body_as_the_event_log_and_a_signature_it_can_check(): void
    {
        $webhook = $this->makeWebhook();
        Http::fake(['erp.example.com/*' => Http::response('', 200)]);

        ApiEvents::record('zztest.changed', 'ZZ-HOOK-1', '1', ['status' => 2]);
        $this->assertSame(1, WebhookDispatcher::run());

        $event = $this->events()->where('subject_id', 'ZZ-HOOK-1')->first();
        $admin = $this->tokenFor($this->makeAdmin(['admin_product.index']), ['admin']);
        $fromLog = $this->getWithToken($this->v2Core() . '/events?after=' . ($event->id - 1) . '&page[size]=1', $admin)->assertOk()->json('data.0');
        Http::assertSent(function (Request $request) use ($event, $fromLog) {
            return $request->url() === 'https://erp.example.com/hook'
                && $request->method() === 'POST'
                && $request->header('X-GP247-Event-Id')[0] === (string) $event->id
                && $request->header('X-GP247-Event-Type')[0] === 'zztest.changed'
                && json_decode($request->body(), true) === $fromLog
                && WebhookSignature::verify('zz-secret', $request->header(WebhookSignature::HEADER)[0], $request->body());
        });
        $delivery = $this->deliveriesOf($webhook)->sole();
        $this->assertSame(AdminApiWebhookDelivery::DELIVERED, $delivery->status);
        $this->assertSame(200, (int) $delivery->last_status_code);
    }

    public function test_an_event_of_a_type_the_webhook_does_not_follow_is_not_sent(): void
    {
        $webhook = $this->makeWebhook(['zztest.changed']);
        Http::fake();

        ApiEvents::record('zztest.other', 'ZZ-HOOK-2', '1', ['status' => 1]);
        WebhookDispatcher::run();

        $this->assertCount(0, $this->deliveriesOf($webhook));
        Http::assertNothingSent();
    }

    public function test_a_failing_endpoint_is_retried_with_growing_delays_and_given_up_after_six_attempts(): void
    {
        $webhook = $this->makeWebhook();
        Http::fake(['erp.example.com/*' => Http::response('down', 500)]);
        Carbon::setTestNow(Carbon::parse('2026-11-01 08:00:00'));
        ApiEvents::record('zztest.changed', 'ZZ-HOOK-3', '1', ['status' => 1]);

        $waits = [];
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->assertSame(1, WebhookDispatcher::run(), "attempt {$attempt}");
            $delivery = $this->deliveriesOf($webhook)->sole();
            if ($delivery->next_attempt_at !== null) {
                $waits[] = (int) round(Carbon::now()->diffInMinutes($delivery->next_attempt_at));
                $this->assertSame(0, WebhookDispatcher::run(), 'nothing is sent before the retry is due');
                Carbon::setTestNow($delivery->next_attempt_at->copy()->addSecond());
            }
        }

        $this->assertSame([1, 5, 30, 120, 720], $waits);
        $delivery = $this->deliveriesOf($webhook)->sole();
        $this->assertSame(AdminApiWebhookDelivery::FAILED, $delivery->status);
        $this->assertSame(6, $delivery->attempts);
        $this->assertSame(500, (int) $delivery->last_status_code);
        Http::assertSentCount(6);
    }

    public function test_a_webhook_failing_fifty_times_in_a_row_is_turned_off_with_the_reason(): void
    {
        $webhook = $this->makeWebhook();
        $webhook->update(['consecutive_failures' => 49]);
        Http::fake(['erp.example.com/*' => Http::response('', 503)]);

        ApiEvents::record('zztest.changed', 'ZZ-HOOK-4', '1', ['status' => 1]);
        WebhookDispatcher::run();

        $fresh = AdminApiWebhook::find($webhook->id);
        $this->assertSame(0, $fresh->status);
        $this->assertStringStartsWith('too_many_failures', (string) $fresh->disabled_reason);
        ApiEvents::record('zztest.changed', 'ZZ-HOOK-5', '1', ['status' => 1]);
        $this->assertCount(1, $this->deliveriesOf($webhook), 'a webhook turned off gets no new deliveries');
    }

    public function test_a_redirect_is_never_followed(): void
    {
        $webhook = $this->makeWebhook();
        Http::fake(['erp.example.com/*' => Http::response('', 302, ['Location' => 'https://169.254.169.254/latest/meta-data'])]);

        ApiEvents::record('zztest.changed', 'ZZ-HOOK-6', '1', ['status' => 1]);
        WebhookDispatcher::run();

        Http::assertSentCount(1);
        $delivery = $this->deliveriesOf($webhook)->sole();
        $this->assertSame(AdminApiWebhookDelivery::PENDING, $delivery->status);
        $this->assertSame(302, (int) $delivery->last_status_code);
    }

    public function test_a_host_answering_with_an_internal_address_at_send_time_is_not_called(): void
    {
        $webhook = $this->makeWebhook();
        Http::fake();
        $this->dns['erp.example.com'] = ['10.0.0.8'];

        ApiEvents::record('zztest.changed', 'ZZ-HOOK-7', '1', ['status' => 1]);
        WebhookDispatcher::run();

        Http::assertNothingSent();
        $delivery = $this->deliveriesOf($webhook)->sole();
        $this->assertSame('blocked_address', $delivery->last_error);
        $this->assertSame(1, $delivery->attempts);
    }

    public function test_one_run_sends_at_most_five_and_stops_when_its_time_is_spent(): void
    {
        $webhook = $this->makeWebhook();
        Http::fake(['erp.example.com/*' => Http::response('', 200)]);
        for ($i = 1; $i <= 7; $i++) {
            ApiEvents::record('zztest.changed', 'ZZ-HOOK-B' . $i, '1', ['status' => 1]);
        }

        $this->assertSame(5, WebhookDispatcher::run());
        $this->assertSame(0, WebhookDispatcher::run(0.0), 'no time left, nothing sent');
        $this->assertSame(2, WebhookDispatcher::run());
        $this->assertSame(7, $this->deliveriesOf($webhook)->where('status', AdminApiWebhookDelivery::DELIVERED)->count());
    }

    public function test_without_webhooks_recording_an_event_costs_no_query_on_webhook_tables(): void
    {
        WebhookDispatcher::forgetWebhooks();
        ApiEvents::record('zztest.changed', 'ZZ-HOOK-WARM', '1', ['status' => 1]);
        $queries = [];
        DB::connection(GP247_DB_CONNECTION)->listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'api_webhook')) {
                $queries[] = $query->sql;
            }
        });

        ApiEvents::record('zztest.changed', 'ZZ-HOOK-8', '1', ['status' => 1]);

        $this->assertSame([], $queries);
    }

    public function test_a_change_rolled_back_leaves_nothing_to_deliver(): void
    {
        $webhook = $this->makeWebhook();
        $db = DB::connection(GP247_DB_CONNECTION);

        $db->beginTransaction();
        ApiEvents::record('zztest.changed', 'ZZ-HOOK-9', '1', ['status' => 1]);
        $db->rollBack();

        $this->assertCount(0, $this->deliveriesOf($webhook));
    }

    public function test_a_due_delivery_is_sent_after_a_later_request_and_then_no_more_work_is_left(): void
    {
        $webhook = $this->makeWebhook();
        Http::fake(['erp.example.com/*' => Http::response('', 200)]);
        ApiEvents::record('zztest.changed', 'ZZ-HOOK-10', '1', ['status' => 1]);
        $this->app->forgetInstance('gp247.webhook_run_scheduled');
        $this->withoutDefer();

        WebhookDispatcher::kickIfDue();

        $this->assertSame(AdminApiWebhookDelivery::DELIVERED, $this->deliveriesOf($webhook)->sole()->status);
        $this->assertNull(cache()->get(WebhookDispatcher::DUE_KEY), 'nothing left to send, no work on later requests');
    }

    public function test_a_broken_cache_never_breaks_the_visitor_request_that_checks_for_due_deliveries(): void
    {
        \Illuminate\Support\Facades\Cache::shouldReceive('get')
            ->with(WebhookDispatcher::DUE_KEY)
            ->andThrow(new \RuntimeException('cache table missing'));

        WebhookDispatcher::kickIfDue();

        $this->addToAssertionCount(1); // reaching here: the failure was reported, not thrown
    }
}
