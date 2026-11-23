<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Models\ShopPaymentStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use GP247\Shop\Tests\TestCase;

/**
 * deriveFrom compares money to the minor unit, not by float equality (F8). received/balance are sums of many ledger rows, so
 * they carry accumulated float error; a half-unit tolerance keeps a fully-paid order
 * out of "partial" and a near-zero overpayment out of "refund".
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-payment-status-float-tolerance
 * @aidlc-adr shop-admin_payment-status-enum-alignment
 */
class PaymentStatusToleranceTest extends TestCase
{
    /**
     * @return array<string, array{0: float, 1: float, 2: int}>
     */
    public static function cases(): array
    {
        return [
            'nothing paid'                 => [0.0, 100.0, ShopPaymentStatus::UNPAID],
            'tiny received noise = unpaid' => [0.004, 99.996, ShopPaymentStatus::UNPAID],
            'exactly paid'                 => [100.0, 0.0, ShopPaymentStatus::PAID],
            'paid within tolerance'        => [100.0, 0.004, ShopPaymentStatus::PAID],
            'paid float noise'             => [100.0, 1.0e-13, ShopPaymentStatus::PAID],
            'partial beyond tolerance'     => [40.0, 60.0, ShopPaymentStatus::PARTIAL],
            'partial just over tolerance'  => [40.0, 0.006, ShopPaymentStatus::PARTIAL],
            'overpaid = refund'            => [120.0, -20.0, ShopPaymentStatus::REFUND],
            'overpaid within tolerance is still paid' => [100.0, -0.004, ShopPaymentStatus::PAID],
            'overpaid just beyond tolerance = refund' => [100.006, -0.006, ShopPaymentStatus::REFUND],
        ];
    }

    #[DataProvider('cases')]
    public function test_derive_from_uses_minor_unit_tolerance(float $received, float $balance, int $expected): void
    {
        $this->assertSame($expected, ShopPaymentStatus::deriveFrom($received, $balance));
    }

    /**
     * The classic accumulation that float equality got wrong: 100 paid in thirds is
     * fully paid, not partial.
     */
    public function test_thirds_accumulation_is_paid_not_partial(): void
    {
        $received = 33.33 + 33.33 + 33.34;
        $balance = 100.0 - $received;

        $this->assertSame(ShopPaymentStatus::PAID, ShopPaymentStatus::deriveFrom($received, $balance));
    }
}
