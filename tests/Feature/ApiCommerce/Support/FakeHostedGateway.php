<?php

namespace GP247\Shop\Tests\Feature\ApiCommerce\Support;

use GP247\Shop\Payment\Contracts\OrderStatusAfterPayment;
use GP247\Shop\Payment\Contracts\PaymentGateway;
use GP247\Shop\Payment\Models\PaymentMovement;
use GP247\Shop\Payment\Models\PaymentRequest;
use GP247\Shop\Payment\Support\CollectResult;
use GP247\Shop\Payment\Support\RefundResult;

/**
 * Gateway double for API orders: hands out a hosted-page URL per payment request and
 * remembers every collect() call, or fails on demand.
 */
class FakeHostedGateway implements PaymentGateway, OrderStatusAfterPayment
{
    /** @var array<string, int|null> Order status per event (paid / refunded); empty = declare none. */
    public static array $statuses = [];

    /** @var array<int, int> Request ids passed to collect(). */
    public static array $collected = [];

    /** @var bool When true collect() throws (gateway down). */
    public static bool $throws = false;

    /**
     * @return void
     */
    public static function reset(): void
    {
        self::$collected = [];
        self::$throws = false;
        self::$statuses = [];
    }

    public function key(): string
    {
        return 'zz.hosted';
    }

    public function capabilities(): array
    {
        return [self::CAP_COLLECT];
    }

    public function availableFor(PaymentRequest $request): bool
    {
        return true;
    }

    public function collect(PaymentRequest $request): CollectResult
    {
        if (self::$throws) {
            throw new \RuntimeException('gateway down');
        }
        self::$collected[] = (int) $request->id;

        return new CollectResult('https://pay.example.test/session/' . $request->id, 'zz_sess_' . $request->id . '_' . count(self::$collected));
    }

    public function refund(PaymentRequest $request, PaymentMovement $original, float $amount, ?string $idempotencyKey = null): RefundResult
    {
        throw new \RuntimeException('not used');
    }

    /**
     * @param string $event
     * @param string $storeId
     * @return int|null
     */
    public function orderStatusAfter(string $event, string $storeId): ?int
    {
        return self::$statuses[$event] ?? null;
    }
}
