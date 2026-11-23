<?php

namespace GP247\Shop\Tests\Feature\ApiSync\Support;

use GP247\Core\Api\Sync\OutboundUrlGuard;
use GP247\Core\Api\Sync\WebhookDispatcher;
use GP247\Core\Models\AdminApiWebhook;
use GP247\Core\Models\AdminApiWebhookDelivery;

/**
 * Webhooks of the tests (with EventLogFixtures): a DNS that answers from a map (no network),
 * webhooks created straight in the table, and their deliveries.
 */
trait WebhookFixtures
{
    /** @var array<string, string[]> host => addresses the fake DNS answers */
    protected array $dns = ['erp.example.com' => ['93.184.216.34']];

    /**
     * Answer DNS from $this->dns for this test.
     *
     * @return void
     */
    protected function useFakeDns(): void
    {
        OutboundUrlGuard::resolveUsing(fn (string $host) => $this->dns[$host] ?? []);
    }

    /**
     * @return void
     */
    protected function forgetFakeDns(): void
    {
        OutboundUrlGuard::resolveUsing(null);
    }

    /**
     * An active webhook following $types, signed with "zz-secret".
     *
     * @param string[] $types
     * @param string   $url
     * @return AdminApiWebhook
     */
    protected function makeWebhook(array $types = ['zztest.changed'], string $url = 'https://erp.example.com/hook'): AdminApiWebhook
    {
        $webhook = AdminApiWebhook::create(['url' => $url, 'event_types' => $types, 'secret' => 'zz-secret', 'status' => 1]);
        WebhookDispatcher::forgetWebhooks();

        return $webhook;
    }

    /**
     * @param AdminApiWebhook $webhook
     * @return \Illuminate\Support\Collection<int, AdminApiWebhookDelivery>
     */
    protected function deliveriesOf(AdminApiWebhook $webhook)
    {
        return AdminApiWebhookDelivery::where('webhook_id', $webhook->id)->orderBy('id')->get();
    }
}
