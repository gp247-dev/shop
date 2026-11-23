<?php

namespace GP247\Shop\Tests\Unit\ShopShipping;

use GP247\Shop\Shipping\LengthConverter;
use PHPUnit\Framework\TestCase;

/**
 * Product dimensions are stored with their own unit; carriers get whole centimetres.
 */
class LengthConverterTest extends TestCase
{
    public function test_every_supported_unit_becomes_whole_centimetres(): void
    {
        $this->assertSame(25, LengthConverter::toCm(250, 'mm'));
        $this->assertSame(30, LengthConverter::toCm(30, 'cm'));
        $this->assertSame(120, LengthConverter::toCm(1.2, 'm'));
        $this->assertSame(25, LengthConverter::toCm(10, 'in'));
        $this->assertSame(30, LengthConverter::toCm(1, 'ft'));
        $this->assertSame(15, LengthConverter::toCm(15, null), 'unknown / empty unit is taken as cm');
        $this->assertSame(1, LengthConverter::toCm(0.2, 'cm'), 'a real dimension never rounds down to 0');
        $this->assertSame(0, LengthConverter::toCm(0, 'cm'));
        $this->assertSame(0, LengthConverter::toCm(-3, 'm'));
    }
}
