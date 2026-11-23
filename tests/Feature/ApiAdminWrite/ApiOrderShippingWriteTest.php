<?php

namespace GP247\Shop\Tests\Feature\ApiAdminWrite;

use GP247\Shop\Admin\Livewire\OrderManager;
use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopShippingStatus;
use GP247\Shop\Shipping\Models\ShopShipment;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\Feature\ApiAdminWrite\Support\OrderWriteFixtures;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * A warehouse or shipping tool reports "shipped, tracking X" through the admin API: the
 * order's shipping status and a manual shipment carrying the tracking code are kept
 * where the admin screens show them. The payment status is never written this way.
 *
 * @aidlc-unit api-admin-write
 * @aidlc-story US-api-admin-write-order-shipping
 * @aidlc-adr api-admin-write_write-authorization-as-admin-action
 */
#[Group('api-admin-write')]
class ApiOrderShippingWriteTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;
    use OrderWriteFixtures;
    use InteractsWithAdminUser;

    public function test_a_shipping_tool_reports_the_shipping_status_and_the_tracking_code(): void
    {
        [$order] = $this->orderWithStock();
        $status = $this->otherShippingStatus($order);

        $this->write($this->writerToken(), 'order/' . $order->id . '/shipping', [
            'shipping_status' => $status, 'tracking_number' => 'ZZTRK123', 'tracking_url' => 'https://track.example.com/ZZTRK123',
        ])->assertOk()->assertJsonPath('data.shipping_status', $status);

        $this->assertSame($status, (int) ShopOrder::find($order->id)->shipping_status);
        $shipment = ShopShipment::where('order_id', $order->id)->where('plugin_key', 'manual')->first();
        $this->assertNotNull($shipment);
        $this->assertSame('ZZTRK123', $shipment->tracking_number);
        $this->assertSame('https://track.example.com/ZZTRK123', $shipment->tracking_url);
        $this->assertGreaterThan(0, $this->historyOf($order)->count());
    }

    public function test_sending_another_tracking_code_updates_the_manual_shipment(): void
    {
        [$order] = $this->orderWithStock();
        $token = $this->writerToken();

        $this->write($token, 'order/' . $order->id . '/shipping', ['tracking_number' => 'ZZTRK-A'])->assertOk();
        $this->write($token, 'order/' . $order->id . '/shipping', ['tracking_number' => 'ZZTRK-B'])->assertOk();

        $manual = ShopShipment::where('order_id', $order->id)->where('plugin_key', 'manual')->get();
        $this->assertCount(1, $manual);
        $this->assertSame('ZZTRK-B', $manual[0]->tracking_number);
    }

    public function test_an_unknown_shipping_status_a_payment_status_or_an_empty_body_is_refused(): void
    {
        [$order] = $this->orderWithStock();
        $token = $this->writerToken();

        $this->write($token, 'order/' . $order->id . '/shipping', ['shipping_status' => 987654])->assertStatus(422);
        $this->write($token, 'order/' . $order->id . '/shipping', ['payment_status' => 3])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->write($token, 'order/' . $order->id . '/shipping', [])->assertStatus(422);
        $this->write($token, 'order/' . $order->id . '/shipping', ['tracking_url' => 'javascript:alert(1)', 'tracking_number' => 'X1'])->assertStatus(422);
    }

    public function test_the_admin_screen_still_records_a_shipping_status_change_in_the_history(): void
    {
        [$order] = $this->orderWithStock();
        $status = $this->otherShippingStatus($order);
        $before = $this->historyOf($order)->count();
        $this->asUser(administrator: true);

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])->call('changeShippingStatus', $status);

        $this->assertSame($status, (int) ShopOrder::find($order->id)->shipping_status);
        $this->assertSame($before + 1, $this->historyOf($order)->count());
    }

    /**
     * A shipping status id different from the order's.
     *
     * @param ShopOrder $order
     * @return int
     */
    private function otherShippingStatus(ShopOrder $order): int
    {
        $ids = array_map('intval', array_keys(ShopShippingStatus::getIdAll()));
        $this->assertNotEmpty($ids, 'The site must have shipping statuses');
        foreach ($ids as $id) {
            if ($id !== (int) $order->shipping_status) {
                return $id;
            }
        }
        $this->markTestSkipped('Only one shipping status on this site');
    }

    /**
     * @return string
     */
    private function writerToken(): string
    {
        return $this->tokenFor($this->makeAdmin([], ['administrator']), ['admin']);
    }
}
