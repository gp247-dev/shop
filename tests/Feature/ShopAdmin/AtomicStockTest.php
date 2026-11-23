<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Models\ShopProduct;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\ConfiguresStockPolicy;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopOrders;
use GP247\Shop\Tests\TestCase;

/**
 * Atomic conditional stock movement (C2).
 *
 * updateStock() used to read stock into PHP, subtract, and save — with no lock, two
 * concurrent orders could both pass the check and oversell, or both write the same
 * decremented value and lose one (RISK-TECH-stock-race-condition). The move is now a
 * single `UPDATE ... WHERE stock >= ?`, so the shortage check is at write time. These
 * tests pin the guard (blocks at zero), the escape (overselling allowed), the return
 * path (never guarded), sold accumulation, and BUILD components.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-atomic-stock-movement
 * @aidlc-adr compat-foundation_atomic-stock-movement
 */
class AtomicStockTest extends TestCase
{
    use ConfiguresStockPolicy;
    use DatabaseTransactions;
    use MakesShopOrders;

    /**
     * Fresh stock figure.
     *
     * @param ShopProduct $p
     * @return float
     */
    private function stockOf(ShopProduct $p): float
    {
        return (float) ShopProduct::find($p->id)->stock;
    }

    /**
     * A decrement is refused (writes nothing) once stock is exhausted, when the shop
     * manages stock and forbids overselling.
     */
    public function test_guarded_decrement_stops_at_zero(): void
    {
        $this->setProductStock(true);
        $this->setBuyOutOfStock(false);
        $product = $this->makeProduct(['stock' => 1, 'sold' => 0]);

        $this->assertTrue(ShopProduct::updateStock($product->id, 1), 'first unit sells');
        $this->assertEqualsWithDelta(0, $this->stockOf($product), 0.01);

        $this->assertFalse(ShopProduct::updateStock($product->id, 1), 'no stock left → refused');
        $this->assertEqualsWithDelta(0, $this->stockOf($product), 0.01, 'stock never goes negative');
    }

    /**
     * The race the design targets: two decrements contend for one unit; exactly one
     * wins, the other is refused — no oversell.
     */
    public function test_two_decrements_for_one_unit_only_one_wins(): void
    {
        $this->setProductStock(true);
        $this->setBuyOutOfStock(false);
        $product = $this->makeProduct(['stock' => 1, 'sold' => 0]);

        $first = ShopProduct::updateStock($product->id, 1);
        $second = ShopProduct::updateStock($product->id, 1);

        $this->assertTrue($first);
        $this->assertFalse($second);
        $this->assertEqualsWithDelta(0, $this->stockOf($product), 0.01);
        $this->assertEqualsWithDelta(1, (float) ShopProduct::find($product->id)->sold, 0.01, 'sold counts the one real sale');
    }

    /**
     * sold accumulates across decrements without a lost update.
     */
    public function test_sold_accumulates_without_lost_update(): void
    {
        $this->setProductStock(true);
        $this->setBuyOutOfStock(false);
        $product = $this->makeProduct(['stock' => 10, 'sold' => 0]);

        ShopProduct::updateStock($product->id, 3);
        ShopProduct::updateStock($product->id, 4);

        $fresh = ShopProduct::find($product->id);
        $this->assertEqualsWithDelta(3, (float) $fresh->stock, 0.01);
        $this->assertEqualsWithDelta(7, (float) $fresh->sold, 0.01);
    }

    /**
     * Returning stock (negative delta) is never guarded — it always applies.
     */
    public function test_return_is_never_guarded(): void
    {
        $this->setProductStock(true);
        $this->setBuyOutOfStock(false);
        $product = $this->makeProduct(['stock' => 0, 'sold' => 5]);

        $this->assertTrue(ShopProduct::updateStock($product->id, -2));
        $fresh = ShopProduct::find($product->id);
        $this->assertEqualsWithDelta(2, (float) $fresh->stock, 0.01);
        $this->assertEqualsWithDelta(3, (float) $fresh->sold, 0.01);
    }

    /**
     * When the shop allows overselling, the guard is off and the decrement goes below
     * zero (the policy is intentional).
     */
    public function test_overselling_allowed_bypasses_the_guard(): void
    {
        $this->setProductStock(true);
        $this->setBuyOutOfStock(true);
        $product = $this->makeProduct(['stock' => 1, 'sold' => 0]);

        $this->assertTrue(ShopProduct::updateStock($product->id, 5));
        $this->assertEqualsWithDelta(-4, $this->stockOf($product), 0.01);
    }
}
