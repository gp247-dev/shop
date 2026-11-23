<?php

namespace GP247\Shop\Tests\Feature\ShopShipping\Support;

use Closure;
use GP247\Shop\Shipping\Contracts\CancelsShipments;
use GP247\Shop\Shipping\Contracts\CreatesShipments;
use GP247\Shop\Shipping\Contracts\HandlesShippingWebhooks;
use GP247\Shop\Shipping\Contracts\ProvidesShippingDocuments;
use GP247\Shop\Shipping\Contracts\ShippingProvider;
use GP247\Shop\Shipping\Contracts\TracksShipments;
use GP247\Shop\Shipping\Data\Money;
use GP247\Shop\Shipping\Data\RateRequest;
use GP247\Shop\Shipping\Data\RateResult;
use GP247\Shop\Shipping\Data\ShipmentRequest;
use GP247\Shop\Shipping\Data\ShipmentResult;
use GP247\Shop\Shipping\Data\ShippingDocument;
use GP247\Shop\Shipping\Data\ShippingRate;
use GP247\Shop\Shipping\Data\TrackingEvent;
use GP247\Shop\Shipping\Data\WebhookResult;
use GP247\Shop\Shipping\ShipmentStatus;
use GP247\Shop\Shipping\ShippingException;
use Illuminate\Http\Request;

/**
 * A scriptable carrier implementing every capability, used as "the third provider"
 * of MC-shipping-provider-zero-core-change: it plugs in through the public contract
 * only. Behaviour is driven by public properties.
 */
class FakeCarrier implements ShippingProvider, CreatesShipments, CancelsShipments, ProvidesShippingDocuments, TracksShipments, HandlesShippingWebhooks
{
    /** @var array<int, ShippingRate> */
    public array $rates = [];
    public ?\Throwable $throw = null;
    public int $rateCalls = 0;
    public string $key = 'FakeCarrier';
    public string $currency = 'VND';
    public ?string $cancelRefusal = null;
    /** @var array<int, TrackingEvent> */
    public array $trackEvents = [];
    public string $webhookSecret = 'secret';
    /** @var array<int, ShipmentRequest> */
    public array $booked = [];
    public bool $duplicate = false;
    public ?RateRequest $lastRequest = null;

    /**
     * @param array<string, float> $services service code => price (carrier currency).
     * @return self
     */
    public static function withServices(array $services, string $key = 'FakeCarrier', string $currency = 'VND'): self
    {
        $carrier = new self();
        $carrier->key = $key;
        $carrier->currency = $currency;
        foreach ($services as $code => $price) {
            $carrier->rates[] = new ShippingRate($key, (string) $code, 'Fake ' . $code, new Money((string) $price, $currency), 1, 3);
        }

        return $carrier;
    }

    /** {@inheritdoc} */
    public function rates(RateRequest $request): RateResult
    {
        $this->rateCalls++;
        $this->lastRequest = $request;
        if ($this->throw !== null) {
            throw $this->throw;
        }

        return new RateResult($this->rates);
    }

    /** {@inheritdoc} */
    public function createShipment(ShipmentRequest $request): ShipmentResult
    {
        $this->booked[] = $request;
        if ($this->throw !== null) {
            throw new ShippingException('carrier refused');
        }

        return new ShipmentResult('TRK-' . $request->bookingReference(), ShipmentStatus::PENDING, new Money('31000', $this->currency), 'https://track.example/TRK-' . $request->bookingReference());
    }

    /** {@inheritdoc} */
    public function shipmentFormFields(): array
    {
        return [['name' => 'ward', 'label' => 'Ward', 'type' => 'text', 'required' => true, 'max' => 100]];
    }

    /** {@inheritdoc} */
    public function cancelShipment(string $trackingNumber, string $storeId): void
    {
        if ($this->cancelRefusal !== null) {
            throw new ShippingException($this->cancelRefusal);
        }
    }

    /** {@inheritdoc} */
    public function document(string $trackingNumber, string $storeId, array $options = []): ShippingDocument
    {
        return new ShippingDocument('%PDF-1.4 fake', 'application/pdf', $trackingNumber . '.pdf');
    }

    /** {@inheritdoc} */
    public function track(string $trackingNumber, string $storeId): array
    {
        return $this->trackEvents;
    }

    /** {@inheritdoc} */
    public function handleWebhook(Request $request, Closure $storeOf): WebhookResult
    {
        if (!hash_equals($this->webhookSecret, (string) $request->query('hash', ''))) {
            return WebhookResult::rejected(403);
        }
        $tracking = (string) $request->input('tracking');
        if ($storeOf($tracking) === null) {
            return new WebhookResult(true, [], 200);
        }
        $status = ShipmentStatus::tryFrom((string) $request->input('status'));

        return new WebhookResult(true, [new TrackingEvent(
            trackingNumber: $tracking,
            status: $status,
            providerStatus: (string) $request->input('status'),
            occurredAt: (int) $request->input('at', time()),
            message: (string) $request->input('reason', ''),
            codSettled: (bool) $request->input('cod_settled', false),
            codAmount: $request->filled('cod') ? new Money((string) $request->input('cod'), $this->currency) : null,
        )]);
    }
}
