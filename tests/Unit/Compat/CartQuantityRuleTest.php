<?php

namespace GP247\Shop\Tests\Unit\Compat;

use GP247\Shop\Services\CartItem;
use PHPUnit\Framework\Attributes\DataProvider;
use GP247\Shop\Tests\TestCase;

/**
 * CartItem::isValidQuantity() — the single source of the storefront cart quantity
 * rule. setQuantity() and the checkout gate both
 * defer to it, so a negative quantity can no longer reach a cart line or an order.
 *
 * Only cases that hold under both product_qty_decimal settings live here; the
 * fractional-quantity branch is covered by the storefront feature tests that
 * toggle the setting.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-adr storefront_cart-quantity-positive-invariant
 */
class CartQuantityRuleTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidQuantityProvider(): array
    {
        return [
            'negative integer' => [-1],
            'large negative' => [-1000],
            'negative numeric string' => ['-3'],
            'negative float' => [-0.5],
            'zero' => [0],
            'zero string' => ['0'],
            'empty string' => [''],
            'null' => [null],
            'non-numeric string' => ['abc'],
            'overflowing numeric string' => ['1e400'],
            'array' => [[1]],
        ];
    }

    #[DataProvider('invalidQuantityProvider')]
    public function test_a_non_positive_or_non_numeric_quantity_is_invalid(mixed $qty): void
    {
        $this->assertFalse(CartItem::isValidQuantity($qty));
    }

    #[DataProvider('invalidQuantityProvider')]
    public function test_set_quantity_refuses_a_non_positive_or_non_numeric_quantity(mixed $qty): void
    {
        $item = new CartItem('ZZ-QTY-RULE', 'ZZ Qty Rule');

        $this->expectException(\InvalidArgumentException::class);
        $item->setQuantity($qty);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function validQuantityProvider(): array
    {
        return [
            'one' => [1],
            'integer' => [12],
            'numeric string' => ['3'],
            'whole float' => [2.0],
        ];
    }

    #[DataProvider('validQuantityProvider')]
    public function test_a_positive_whole_quantity_is_valid_and_stored(mixed $qty): void
    {
        $this->assertTrue(CartItem::isValidQuantity($qty));

        $item = new CartItem('ZZ-QTY-RULE', 'ZZ Qty Rule');
        $item->setQuantity($qty);

        $this->assertEquals($qty, $item->qty);
    }
}
