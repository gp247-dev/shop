<?php

/**
 * @aidlc-story US-shop-shipping-shipment-lifecycle
 * @aidlc-story US-shop-shipping-tracking-webhook
 * @aidlc-story US-shop-shipping-cod
 * @aidlc-story US-shop-shipping-provider-contract
 */

namespace GP247\Shop\Tests\Feature\ShopShipping;

use GP247\Core\Models\AdminConfig;
use GP247\Shop\Admin\Livewire\OrderShipmentPanel;
use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopOrderHistory;
use GP247\Shop\Models\ShopShippingStatus;
use GP247\Shop\Shipping\Data\Money;
use GP247\Shop\Shipping\Data\TrackingEvent;
use GP247\Shop\Shipping\Models\ShopShipment;
use GP247\Shop\Shipping\Models\ShopShipmentEvent;
use GP247\Shop\Shipping\ShipmentService;
use GP247\Shop\Shipping\ShipmentStatus;
use GP247\Shop\Shipping\ShippingException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopOrders;
use GP247\Shop\Tests\Feature\ShopShipping\Support\FakeCarrier;
use GP247\Shop\Tests\Feature\ShopShipping\Support\FakeShippingRegistry;
use GP247\Shop\Tests\TestCase;

/**
 * A shipment booked through the standard contract lives its whole life in the core:
 * booked from the order, cancelled while the carrier allows it, moved by verified
 * carrier events exactly once, folded into the order's shipping status, and — when
 * the store asks for it — its reconciled COD cash recorded on the order once.
 */
#[Group('shop-shipping')]
class ShipmentLifecycleTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use MakesShopOrders;

    private FakeCarrier $carrier;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        if (!ShipmentService::available()) {
            $this->markTestSkipped('shop_shipment table missing: run gp247:shop-update.');
        }
        $this->carrier = FakeCarrier::withServices(['std' => 30000], 'FakeCarrier', 'USD');
        config(['gp247-config.shop.shipping_providers.FakeCarrier' => [
            'driver' => FakeCarrier::class,
            'capabilities' => ['rates', 'shipments', 'cancel', 'documents', 'tracking', 'webhook', 'cod'],
        ]]);
        FakeShippingRegistry::bind(['FakeCarrier' => $this->carrier]);
        $this->resetConfigCache();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->resetConfigCache();
        parent::tearDown();
    }

    public function test_booking_a_shipment_stores_the_tracking_number_a_first_event_and_a_history_line(): void
    {
        $order = $this->shippableOrder();

        $shipment = ShipmentService::instance()->create($order, $this->form($order), '0');

        $this->assertSame('TRK-' . $order->id, $shipment->tracking_number);
        $this->assertSame(ShipmentStatus::PENDING->value, $shipment->status);
        $this->assertEqualsWithDelta(31000, $shipment->fee, 0.001);
        $this->assertSame(1, ShopShipmentEvent::where('shipment_id', $shipment->id)->count());
        $this->assertSame(1, ShopOrderHistory::where('order_id', $order->id)->where('content', 'like', '%TRK-%')->count());
        $this->assertSame(ShopShippingStatus::NOT_SENT, (int) ShopOrder::find($order->id)->shipping_status);
        $this->assertSame('Phường 7', $this->carrier->booked[0]->destination->ward, 'carrier receives the ward typed in the form');
        $this->assertSame(['ward' => 'Phường 7'], $this->carrier->booked[0]->extra, 'only fields the carrier declared are passed as extras');
    }

    public function test_a_second_shipment_is_refused_while_one_is_active_and_a_locked_order_cannot_be_shipped(): void
    {
        $order = $this->shippableOrder();
        ShipmentService::instance()->create($order, $this->form($order), '0');

        try {
            ShipmentService::instance()->create($order, $this->form($order), '0');
            $this->fail('second active shipment must be refused');
        } catch (ShippingException $e) {
            $this->assertSame(1, ShopShipment::where('order_id', $order->id)->count());
        }

        $locked = $this->shippableOrder(['status' => \GP247\Shop\Models\ShopOrderStatus::DONE]);
        $this->expectException(ShippingException::class);
        ShipmentService::instance()->create($locked, $this->form($locked), '0');
    }

    public function test_cancelling_marks_the_shipment_cancelled_and_a_carrier_refusal_leaves_it_untouched(): void
    {
        $order = $this->shippableOrder();
        $shipment = ShipmentService::instance()->create($order, $this->form($order), '0');

        $this->carrier->cancelRefusal = 'already picked up';
        try {
            ShipmentService::instance()->cancel($shipment, '0');
            $this->fail('refusal must surface');
        } catch (ShippingException $e) {
            $this->assertSame('already picked up', $e->getMessage());
        }
        $this->assertSame(ShipmentStatus::PENDING->value, $shipment->fresh()->status);

        $this->carrier->cancelRefusal = null;
        ShipmentService::instance()->cancel($shipment->fresh(), '0');
        $this->assertSame(ShipmentStatus::CANCELLED->value, $shipment->fresh()->status);
        $this->assertNull(ShipmentService::instance()->activeShipment($order));
    }

    public function test_a_verified_webhook_moves_the_shipment_and_the_order_once_even_when_replayed(): void
    {
        $order = $this->shippableOrder();
        $shipment = ShipmentService::instance()->create($order, $this->form($order), '0');
        $url = route('shop.shipping.webhook', ['pluginKey' => 'FakeCarrier']) . '?hash=secret';
        $payload = ['tracking' => $shipment->tracking_number, 'status' => 'delivered', 'at' => time() + 10];

        $this->post($url, $payload)->assertOk();
        $this->post($url, $payload)->assertOk();

        $this->assertSame(ShipmentStatus::DELIVERED->value, $shipment->fresh()->status);
        $this->assertSame(ShopShippingStatus::SHIPPING_DONE, (int) ShopOrder::find($order->id)->shipping_status);
        $this->assertSame(2, ShopShipmentEvent::where('shipment_id', $shipment->id)->count(), 'created + delivered, the replay adds nothing');
    }

    public function test_a_webhook_that_fails_verification_writes_nothing_and_an_unknown_tracking_is_acknowledged(): void
    {
        $order = $this->shippableOrder();
        $shipment = ShipmentService::instance()->create($order, $this->form($order), '0');
        $base = route('shop.shipping.webhook', ['pluginKey' => 'FakeCarrier']);

        $this->post($base . '?hash=wrong', ['tracking' => $shipment->tracking_number, 'status' => 'delivered'])->assertStatus(403);
        $this->assertSame(ShipmentStatus::PENDING->value, $shipment->fresh()->status);

        $this->post($base . '?hash=secret', ['tracking' => 'NOPE', 'status' => 'delivered'])->assertOk();
        $this->post(route('shop.shipping.webhook', ['pluginKey' => 'NoSuchCarrier']) . '?hash=secret', [])->assertNotFound();
    }

    public function test_an_older_event_never_moves_the_shipment_backwards(): void
    {
        $order = $this->shippableOrder();
        $shipment = ShipmentService::instance()->create($order, $this->form($order), '0');
        $now = time();

        ShipmentService::instance()->applyEvents('FakeCarrier', [
            new TrackingEvent($shipment->tracking_number, ShipmentStatus::OUT_FOR_DELIVERY, '4', $now + 100),
            new TrackingEvent($shipment->tracking_number, ShipmentStatus::PICKED_UP, '3', $now + 50),
        ], 'sync');

        $this->assertSame(ShipmentStatus::OUT_FOR_DELIVERY->value, $shipment->fresh()->status);
    }

    public function test_reconciled_cod_cash_is_recorded_once_only_when_the_store_enabled_it(): void
    {
        $order = $this->shippableOrder(['payment_method' => 'CashPayment', 'total' => 50, 'balance' => 50]);
        // WHY explicit: the switch is a site setting; a dev database where it is on must not decide the outcome.
        $this->setStoreConfig('shipping_cod_auto_record', '0', (string) $order->store_id);
        $shipment = ShipmentService::instance()->create($order, $this->form($order, ['cod_amount' => 50]), '0');
        $settled = new TrackingEvent($shipment->tracking_number, ShipmentStatus::DELIVERED, '6', time() + 10, '', true, new Money('50', 'USD'));

        ShipmentService::instance()->applyEvents('FakeCarrier', [$settled], 'webhook');
        $this->assertEqualsWithDelta(50, (float) ShopOrder::find($order->id)->balance, 0.001, 'switch off: nothing recorded');

        $this->setStoreConfig('shipping_cod_auto_record', '1', (string) $order->store_id);
        $again = new TrackingEvent($shipment->tracking_number, ShipmentStatus::DELIVERED, '6', time() + 20, '', true, new Money('50', 'USD'));
        ShipmentService::instance()->applyEvents('FakeCarrier', [$again], 'webhook');
        ShipmentService::instance()->applyEvents('FakeCarrier', [new TrackingEvent($shipment->tracking_number, ShipmentStatus::DELIVERED, '6', time() + 30, '', true, new Money('50', 'USD'))], 'sync');

        $fresh = ShopOrder::find($order->id);
        $this->assertEqualsWithDelta(0, (float) $fresh->balance, 0.001);
        $this->assertSame(1, $fresh->transactions()->where('gateway_transaction_id', 'FakeCarrier:' . $shipment->tracking_number)->count());
    }

    public function test_a_cancelled_order_can_be_booked_again_under_a_new_booking_attempt(): void
    {
        $order = $this->shippableOrder();
        $first = ShipmentService::instance()->create($order, $this->form($order), '0');
        ShipmentService::instance()->cancel($first, '0');

        $second = ShipmentService::instance()->create($order, $this->form($order), '0');

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, $this->carrier->booked[1]->attempt, 'the carrier gets a fresh booking reference');
        $this->assertTrue($second->isActive());
    }

    public function test_a_late_carrier_event_never_revives_a_cancelled_shipment(): void
    {
        $order = $this->shippableOrder();
        $shipment = ShipmentService::instance()->create($order, $this->form($order), '0');
        ShipmentService::instance()->cancel($shipment, '0');

        ShipmentService::instance()->applyEvents('FakeCarrier', [new TrackingEvent($shipment->tracking_number, ShipmentStatus::IN_TRANSIT, '10', time() + 60)], 'webhook');

        $this->assertSame(ShipmentStatus::CANCELLED->value, $shipment->fresh()->status);
        $this->assertSame(ShopShippingStatus::NOT_SENT, (int) ShopOrder::find($order->id)->shipping_status);
    }

    public function test_recording_cod_cash_that_matches_the_balance_writes_no_mismatch_warning(): void
    {
        $order = $this->shippableOrder(['payment_method' => 'CashPayment', 'total' => 50, 'balance' => 50]);
        $shipment = ShipmentService::instance()->create($order, $this->form($order, ['cod_amount' => 50]), '0');
        $this->setStoreConfig('shipping_cod_auto_record', '1', (string) $order->store_id);

        ShipmentService::instance()->applyEvents('FakeCarrier', [new TrackingEvent($shipment->tracking_number, ShipmentStatus::DELIVERED, '6', time() + 10, '', true, new Money('50', 'USD'))], 'webhook');

        $histories = ShopOrderHistory::where('order_id', $order->id)->pluck('content')->all();
        $mismatchPrefix = strtok(gp247_language_render('admin.shipment.history_cod_mismatch', ['carrier_amount' => '#', 'balance' => '#']), '#');
        $this->assertSame([], array_values(array_filter($histories, static fn ($c): bool => str_starts_with((string) $c, (string) $mismatchPrefix))), 'cash equal to the balance is not a mismatch');
        $this->assertEqualsWithDelta(0, (float) ShopOrder::find($order->id)->balance, 0.001);
    }

    public function test_sync_applies_carrier_events_and_lazy_refresh_respects_the_interval(): void
    {
        $order = $this->shippableOrder();
        $shipment = ShipmentService::instance()->create($order, $this->form($order), '0');
        $this->carrier->trackEvents = [new TrackingEvent($shipment->tracking_number, ShipmentStatus::IN_TRANSIT, '10', time() + 5)];

        $this->assertFalse(ShipmentService::instance()->lazyRefresh($shipment->fresh()), 'just synced at booking: too early');
        $shipment->forceFill(['last_synced_at' => now()->subHours(2)])->save();
        $this->assertTrue(ShipmentService::instance()->lazyRefresh($shipment->fresh()));

        $this->assertSame(ShipmentStatus::IN_TRANSIT->value, $shipment->fresh()->status);
        $this->assertSame(ShopShippingStatus::SENDING, (int) ShopOrder::find($order->id)->shipping_status);
    }

    public function test_the_order_screen_panel_books_a_shipment_for_an_admin_allowed_to_edit_orders(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $order = $this->shippableOrder();

        Livewire::test(OrderShipmentPanel::class, ['orderId' => (string) $order->id, 'scopeStoreId' => null])
            ->assertSee('data-testid="order-shipment-create"', false)
            ->call('openCreate')
            ->assertSet('form.plugin_key', 'FakeCarrier')
            ->set('form.ward', 'Phường 7')
            ->set('form.extra.ward', 'Phường 7')
            ->call('createShipment')
            ->assertHasNoErrors()
            ->assertSee('TRK-' . $order->id);

        $this->assertSame(1, ShopShipment::where('order_id', $order->id)->count());
    }

    public function test_the_panel_never_acts_on_an_order_of_another_store(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $order = $this->shippableOrder();

        Livewire::test(OrderShipmentPanel::class, ['orderId' => (string) $order->id, 'scopeStoreId' => 'OTHER-STORE'])
            ->call('openCreate')
            ->assertSet('creating', false);
    }

    /**
     * @param array<string, mixed> $overrides Order attributes.
     * @return ShopOrder
     */
    private function shippableOrder(array $overrides = []): ShopOrder
    {
        return $this->makeOrder(array_merge([
            'phone' => '0900000000', 'city' => 'Hà Nội', 'address1' => '1 Phố Huế', 'country' => 'VN',
            'subtotal' => 40, 'total' => 40, 'balance' => 40, 'shipping_method' => 'FakeCarrier', 'payment_method' => 'Paypal',
        ], $overrides));
    }

    /**
     * @param ShopOrder            $order     Order.
     * @param array<string, mixed> $overrides Form values.
     * @return array<string, mixed>
     */
    private function form(ShopOrder $order, array $overrides = []): array
    {
        return array_merge(ShipmentService::instance()->defaults($order), [
            'plugin_key' => 'FakeCarrier', 'service_code' => 'std', 'ward' => 'Phường 7',
            'extra' => ['ward' => 'Phường 7', 'not_declared' => 'x'],
        ], $overrides);
    }

    /**
     * @param string $key     Config key.
     * @param string $value   Value.
     * @param string $storeId Store id.
     * @return void
     */
    private function setStoreConfig(string $key, string $value, string $storeId): void
    {
        AdminConfig::updateOrCreate(['key' => $key, 'store_id' => $storeId], ['group' => 'gp247_cart', 'code' => 'shipping_config', 'value' => $value, 'sort' => 0, 'detail' => '']);
        $this->resetConfigCache();
    }

    /**
     * @return void
     */
    private function resetConfigCache(): void
    {
        foreach (['getAllGlobal' => null, 'getAllConfigOfStore' => []] as $name => $empty) {
            $prop = new \ReflectionProperty(AdminConfig::class, $name);
            $prop->setValue(null, $empty);
        }
    }
}
