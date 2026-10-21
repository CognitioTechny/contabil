<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Tests\Application\Calendar;

use CodeIgniter\Test\CIUnitTestCase;
use CognitioTechny\Contabil\Application\Calendar\CreateFiscalYear;
use CognitioTechny\Contabil\Application\Calendar\ListFiscalYears;
use CognitioTechny\Contabil\Application\Calendar\ListPeriods;
use CognitioTechny\Contabil\Application\Repository\EntityGuard;
use CognitioTechny\Contabil\Domain\Exception\ConflictException;
use CognitioTechny\Contabil\Domain\Exception\NotFoundException;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;
use Tests\Support\InMemoryChart;
use Tests\Support\InMemoryFiscalYears;

/**
 * @internal
 */
final class CalendarUseCasesTest extends CIUnitTestCase
{
    private CreateFiscalYear $create;
    private ListFiscalYears $list;
    private ListPeriods $periods;
    private InMemoryChart $entities;

    protected function setUp(): void
    {
        parent::setUp();

        $years          = new InMemoryFiscalYears();
        $this->entities = new InMemoryChart();
        $guard          = new EntityGuard($this->entities);

        $this->create  = new CreateFiscalYear($guard, $years, $years);
        $this->list    = new ListFiscalYears($guard, $years);
        $this->periods = new ListPeriods($years);
    }

    public function testCreatingAYearStoresItWithTwelvePeriods(): void
    {
        [$year, $periods] = $this->create->execute(1, '2026-01-01');

        $this->assertNotNull($year->id);
        $this->assertCount(12, $periods);
        $this->assertSame($year->id, $periods[0]->fiscalYearId);
        $this->assertCount(12, $this->periods->execute(1, (int) $year->id));
    }

    public function testConsecutiveYearsAreAccepted(): void
    {
        $this->create->execute(1, '2026-01-01');
        $this->create->execute(1, '2027-01-01');

        $this->assertSame(['2026', '2027'], array_map(static fn ($y) => $y->label, $this->list->execute(1)));
    }

    public function testOverlappingYearIsRejectedAndLeavesNothingBehind(): void
    {
        $this->create->execute(1, '2026-01-01');

        try {
            $this->create->execute(1, '2026-07-01');
            $this->fail('An overlapping year must be rejected.');
        } catch (ConflictException) {
            $this->assertCount(1, $this->list->execute(1));
        }
    }

    public function testEntitiesHaveIndependentCalendars(): void
    {
        $other = $this->entities->create('Other', null, 'USD', 'ACCRUAL');

        $this->create->execute(1, '2026-01-01');
        $this->create->execute($other, '2026-01-01');

        $this->assertCount(1, $this->list->execute($other));
    }

    public function testInvalidDatesAreRejected(): void
    {
        foreach (['2026-1-1', '2026-02-30', 'yesterday', '', '2026-01-15'] as $date) {
            try {
                $this->create->execute(1, $date);
                $this->fail('Date accepted: ' . $date);
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testUnknownEntityAndYearAreNotFound(): void
    {
        try {
            $this->create->execute(99, '2026-01-01');
            $this->fail('Unknown entity accepted.');
        } catch (NotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(NotFoundException::class);
        $this->periods->execute(1, 12345);
    }

    public function testPeriodsOfAnotherEntitysYearAreNotFound(): void
    {
        [$year] = $this->create->execute(1, '2026-01-01');
        $other  = $this->entities->create('Other', null, 'USD', 'ACCRUAL');

        $this->expectException(NotFoundException::class);
        $this->periods->execute($other, (int) $year->id);
    }
}
