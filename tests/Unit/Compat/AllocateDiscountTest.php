<?php

namespace GP247\Shop\Tests\Unit\Compat;

use GP247\Shop\Tests\TestCase;

/**
 * gp247_allocate_discount() — the shared split behind every discount on an order.
 *
 * Four write paths depend on this one function agreeing with itself: storefront
 * checkout, admin order creation, editing a line, and editing the discount total row.
 * The properties below are what "agreeing" means, and they are cheaper to pin down here
 * than through any of those paths.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-order-discount-pre-tax
 * @aidlc-adr shop-admin_order-discount-pre-tax
 */
class AllocateDiscountTest extends TestCase
{
    /**
     * The central property: shares sum to the discount exactly, for splits that do not
     * divide evenly. Every reconciliation downstream compares those two numbers, so
     * "off by a cent" is a failure, not a rounding detail.
     */
    public function test_shares_always_sum_to_the_discount(): void
    {
        $cases = [
            [[100, 100, 100], 10.00],
            [[33.33, 33.33, 33.34], 24.56],
            [[1, 2, 3, 4, 5], 7.77],
            [[999.99, 0.01], 123.45],
        ];

        foreach ($cases as [$lines, $discount]) {
            $shares = gp247_allocate_discount($lines, $discount);
            $this->assertEqualsWithDelta(
                $discount,
                array_sum($shares),
                0.0001,
                'shares must total the discount for ' . json_encode($lines)
            );
        }
    }

    /**
     * Same inputs, same output — every time. updateSubTotal() re-runs this on each line
     * edit, so anything order-dependent or accumulating would drift invisibly.
     */
    public function test_the_split_is_deterministic(): void
    {
        $lines = [33.33, 11.11, 7.77];

        $first = gp247_allocate_discount($lines, 24.56);
        $second = gp247_allocate_discount($lines, 24.56);

        $this->assertSame($first, $second);
    }

    /**
     * A discount larger than the goods is a typing accident, not an offer: it is capped
     * so the order can never total less than nothing (F17).
     */
    public function test_a_discount_larger_than_the_lines_is_capped(): void
    {
        $shares = gp247_allocate_discount([100, 50], 500);

        $this->assertEqualsWithDelta(150.00, array_sum($shares), 0.0001);
    }

    /**
     * Degenerate inputs return zeros rather than dividing by zero or throwing — these
     * reach the function from real orders (a fully-refunded line, an empty basket).
     */
    public function test_degenerate_inputs_produce_zero_shares(): void
    {
        $this->assertSame([], gp247_allocate_discount([], 10));
        $this->assertSame([0.0, 0.0], gp247_allocate_discount([100, 100], 0));
        $this->assertSame([0.0, 0.0], gp247_allocate_discount([0, 0], 10));
        $this->assertSame([0.0], gp247_allocate_discount([100], -5));
    }

    /**
     * One line takes the whole discount — the common case, and the one where an
     * off-by-a-cent remainder would be most visible on an invoice.
     */
    public function test_a_single_line_takes_the_whole_discount(): void
    {
        $this->assertEqualsWithDelta([12.34], gp247_allocate_discount([100], 12.34), 0.0001);
    }
}
