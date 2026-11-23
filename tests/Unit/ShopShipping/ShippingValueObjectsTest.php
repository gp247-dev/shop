<?php

namespace GP247\Shop\Tests\Unit\ShopShipping;

use GP247\Shop\Models\ShopShippingStatus;
use GP247\Shop\Shipping\Data\Money;
use GP247\Shop\Shipping\Data\RateRequest;
use GP247\Shop\Shipping\Data\RateSnapshot;
use GP247\Shop\Shipping\Data\ShippingAddress;
use GP247\Shop\Shipping\Data\ShippingItem;
use GP247\Shop\Shipping\Data\ShippingRate;
use GP247\Shop\Shipping\ShipmentStatus;
use GP247\Shop\Shipping\WeightConverter;
use PHPUnit\Framework\TestCase;

/**
 * Pure value objects of the shipping contract: unit conversion, the normalised shipment
 * status and how it folds into the order's shipping_status, request hashing and the
 * rate snapshot round-trip.
 */
class ShippingValueObjectsTest extends TestCase
{
    public function test_weight_is_converted_to_whole_grams_from_every_supported_unit(): void
    {
        $this->assertSame(1500, WeightConverter::toGrams(1.5, 'kg'));
        $this->assertSame(250, WeightConverter::toGrams(250, 'g'));
        $this->assertSame(250, WeightConverter::toGrams(250, 'gram'));
        $this->assertSame(454, WeightConverter::toGrams(1, 'lb'));
        $this->assertSame(28, WeightConverter::toGrams(1, 'oz'));
        // Unknown or empty class: the value is taken as grams (the most common storage).
        $this->assertSame(300, WeightConverter::toGrams(300, ''));
        $this->assertSame(300, WeightConverter::toGrams(300, null));
        $this->assertSame(0, WeightConverter::toGrams(-5, 'kg'));
    }

    public function test_shipment_status_folds_into_the_four_order_shipping_statuses(): void
    {
        $this->assertSame(ShopShippingStatus::NOT_SENT, ShipmentStatus::PENDING->orderShippingStatus());
        $this->assertSame(ShopShippingStatus::NOT_SENT, ShipmentStatus::CANCELLED->orderShippingStatus());
        foreach ([ShipmentStatus::PICKED_UP, ShipmentStatus::IN_TRANSIT, ShipmentStatus::OUT_FOR_DELIVERY,
                  ShipmentStatus::DELIVERY_FAILED, ShipmentStatus::RETURNING, ShipmentStatus::EXCEPTION] as $s) {
            $this->assertSame(ShopShippingStatus::SENDING, $s->orderShippingStatus(), $s->value);
        }
        $this->assertSame(ShopShippingStatus::SHIPPING_DONE, ShipmentStatus::DELIVERED->orderShippingStatus());
        $this->assertSame(ShopShippingStatus::REFUNDED, ShipmentStatus::RETURNED->orderShippingStatus());
        $this->assertTrue(ShipmentStatus::DELIVERED->isFinal());
        $this->assertFalse(ShipmentStatus::IN_TRANSIT->isFinal());
    }

    public function test_rate_id_is_the_plugin_key_for_a_single_service_and_key_colon_service_otherwise(): void
    {
        $rate = new ShippingRate('ShippingGHTK', 'road', 'Road', new Money('30000', 'VND'));
        $this->assertSame('ShippingGHTK:road', $rate->rateId(multiService: true));
        $this->assertSame('ShippingGHTK', $rate->rateId(multiService: false));
    }

    public function test_request_hash_changes_with_destination_or_items_but_not_with_key_order(): void
    {
        $a = $this->request('Hà Nội', 2);
        $b = $this->request('Hà Nội', 2);
        $c = $this->request('Đà Nẵng', 2);
        $d = $this->request('Hà Nội', 3);

        $this->assertSame($a->hash(), $b->hash());
        $this->assertNotSame($a->hash(), $c->hash());
        $this->assertNotSame($a->hash(), $d->hash());
    }

    public function test_snapshot_round_trips_through_an_array_and_knows_its_request_and_expiry(): void
    {
        $request = $this->request('Hà Nội', 1);
        $snapshot = new RateSnapshot(
            rateId: 'ShippingGHTK:road',
            pluginKey: 'ShippingGHTK',
            serviceCode: 'road',
            title: 'GHTK — Road',
            providerAmount: new Money('30000', 'VND'),
            baseAmount: 30000.0,
            displayAmount: 30000.0,
            displayCurrency: 'VND',
            displayNative: false,
            requestHash: $request->hash(),
            weightGrams: 500,
            etaMinDays: 2,
            etaMaxDays: 3,
            quotedAt: 1000,
            expiresAt: 2800,
        );

        $copy = RateSnapshot::fromArray($snapshot->toArray());
        $this->assertEquals($snapshot, $copy);
        $this->assertTrue($copy->matches($request));
        $this->assertFalse($copy->matches($this->request('Huế', 1)));
        $this->assertFalse($copy->isExpired(2799));
        $this->assertTrue($copy->isExpired(2800));
        $this->assertNull(RateSnapshot::fromArray(['broken' => true]));
    }

    /**
     * @param string $province Destination province.
     * @param int    $qty      Quantity of the single line.
     * @return RateRequest
     */
    private function request(string $province, int $qty): RateRequest
    {
        $origin = new ShippingAddress(name: 'Shop', phone: '0900000000', country: 'VN', province: 'TP. Hồ Chí Minh', ward: 'Bến Nghé', line1: '1 Lê Lợi');
        $dest = new ShippingAddress(name: 'An', phone: '0911111111', country: 'VN', province: $province, ward: 'Phường 1', line1: '2 Trần Phú');
        $items = [new ShippingItem(name: 'Áo', qty: $qty, weightGrams: 250, unitValue: new Money('100000', 'VND'), sku: 'A1')];

        return new RateRequest('1', $origin, $dest, $items, new Money((string) (100000 * $qty), 'VND'));
    }
}
