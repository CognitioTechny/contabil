<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Tests\Domain;

use CodeIgniter\Test\CIUnitTestCase;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;
use CognitioTechny\Contabil\Domain\Money;

/**
 * @internal
 */
final class MoneyTest extends CIUnitTestCase
{
    public function testParsesDecimalsWithoutFloats(): void
    {
        $this->assertSame(123456, Money::fromDecimal('1234.56')->minor);
        $this->assertSame(-50, Money::fromDecimal('-0.5')->minor);
        $this->assertSame(10, Money::fromDecimal('0.10')->minor);
        $this->assertSame(1999999999999999, Money::fromDecimal('19999999999999.99')->minor);
        $this->assertSame(0, Money::fromDecimal('0.00')->minor);
        $this->assertSame(100, Money::fromDecimal('1.000')->minor);
    }

    public function testRejectsExtraDecimalPlacesAndGarbage(): void
    {
        $this->expectException(ValidationException::class);
        Money::fromDecimal('1.005');
    }

    public function testRejectsNonNumbers(): void
    {
        $this->expectException(ValidationException::class);
        Money::fromDecimal('12,50');
    }

    public function testFormatsWithoutNegativeZero(): void
    {
        $this->assertSame('1234.56', Money::format(123456));
        $this->assertSame('-0.05', Money::format(-5));
        $this->assertSame('0.00', Money::format(0));
        $this->assertSame('0.07', Money::of(7)->toDecimal());
        $this->assertSame('12', Money::format(12, 0));
        $this->assertSame('0.123', Money::format(123, 3));
    }

    public function testArithmetic(): void
    {
        $a = Money::of(1000);
        $b = Money::of(250);

        $this->assertSame(1250, $a->add($b)->minor);
        $this->assertSame(750, $a->subtract($b)->minor);
        $this->assertSame(-1000, $a->negate()->minor);
        $this->assertTrue($a->compareTo($b) > 0);
        $this->assertTrue(Money::zero()->isZero());
        $this->assertTrue($a->isPositive());
        $this->assertTrue($b->negate()->isNegative());
        $this->assertTrue($a->equals(Money::of(1000)));
    }

    public function testRatioRoundsHalfAwayFromZero(): void
    {
        $this->assertSame(33, Money::proportion(100, 1, 3));
        $this->assertSame(67, Money::proportion(100, 2, 3));
        $this->assertSame(3, Money::proportion(5, 1, 2), '2.5 rounds up');
        $this->assertSame(-3, Money::proportion(-5, 1, 2));
        $this->assertSame(100, Money::proportion(100, 7, 7));
        $this->assertSame(0, Money::proportion(100, 0, 7));
        $this->assertSame(45, Money::of(90)->ratio(50, 100)->minor);
    }

    public function testRatioNeverLosesTheTotal(): void
    {
        // Three equal settlements of a 100-cent line: 33 + 33 + 34.
        $recognized = 0;
        $parts      = [];

        foreach ([1, 2, 3] as $step) {
            $cumulative = Money::proportion(100, $step, 3);
            $parts[]    = $cumulative - $recognized;
            $recognized = $cumulative;
        }

        $this->assertSame([33, 34, 33], $parts);
        $this->assertSame(100, array_sum($parts));
    }

    public function testAdditionOverflowIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        Money::of(PHP_INT_MAX)->add(Money::of(1));
    }

    public function testBadProportionIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        Money::proportion(100, 1, 0);
    }
}
