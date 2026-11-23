<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\ConfiguresQtyDecimal;
use GP247\Shop\Tests\TestCase;

/**
 * Unit-level coverage for the quantity-format helpers (ADR-016): `gp247_qty_decimal_enabled()`, `gp247_qty_rule()`,
 * `gp247_qty_format()`. Config-backed (Shop Config `product_qty_decimal`,
 * global), hence a DB-booted Feature test rather than a pure Unit test.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-005
 * @aidlc-adr ADR-016
 */
class QuantityFormatHelperTest extends TestCase
{
    use DatabaseTransactions;
    use ConfiguresQtyDecimal;

    public function test_decimal_disabled_by_default_when_key_not_seeded(): void
    {
        $this->assertFalse(gp247_qty_decimal_enabled());
    }

    public function test_decimal_disabled_when_config_value_is_zero(): void
    {
        $this->setQtyDecimalEnabled(false);

        $this->assertFalse(gp247_qty_decimal_enabled());
    }

    public function test_decimal_enabled_when_config_value_is_one(): void
    {
        $this->setQtyDecimalEnabled(true);

        $this->assertTrue(gp247_qty_decimal_enabled());
    }

    public function test_rule_is_integer_when_decimal_disabled(): void
    {
        $this->setQtyDecimalEnabled(false);

        $this->assertSame('integer|min:1', gp247_qty_rule());
        $this->assertSame('integer|min:0', gp247_qty_rule('0'));
    }

    public function test_rule_is_numeric_when_decimal_enabled(): void
    {
        $this->setQtyDecimalEnabled(true);

        $this->assertSame('numeric|min:0.01', gp247_qty_rule());
        $this->assertSame('numeric|min:0', gp247_qty_rule('1', '0'));
    }

    public function test_format_rounds_to_whole_number_when_decimal_disabled(): void
    {
        $this->setQtyDecimalEnabled(false);

        $this->assertSame('11', gp247_qty_format(10.5));
        $this->assertSame('10', gp247_qty_format(10.4));
    }

    public function test_format_keeps_two_decimals_when_decimal_enabled(): void
    {
        $this->setQtyDecimalEnabled(true);

        $this->assertSame('10.50', gp247_qty_format(10.5));
        $this->assertSame('3.00', gp247_qty_format(3));
    }
}
