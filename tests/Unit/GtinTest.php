<?php

namespace Tests\Unit;

use App\Rules\Gtin;
use App\Support\Format;
use PHPUnit\Framework\TestCase;

class GtinTest extends TestCase
{
    public function test_gtin_check_digits(): void
    {
        $this->assertTrue(Gtin::isValid('4006381333931'));   // EAN-13
        $this->assertTrue(Gtin::isValid('96385074'));        // EAN-8
        $this->assertTrue(Gtin::isValid('036000291452'));    // UPC-A
        $this->assertFalse(Gtin::isValid('4006381333932'));
        $this->assertFalse(Gtin::isValid('400638133393'));
        $this->assertFalse(Gtin::isValid('ABC'));
    }

    public function test_german_decimal_input(): void
    {
        $this->assertSame('12.90', Format::parseDecimal('12,90'));
        $this->assertSame('1299.00', Format::parseDecimal('1.299,00 €'));
        $this->assertSame('12.5', Format::parseDecimal('12.5'));
        $this->assertNull(Format::parseDecimal('zwölf'));
        $this->assertNull(Format::parseDecimal(''));
    }
}
