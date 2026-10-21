<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Tests\Domain;

use CodeIgniter\Test\CIUnitTestCase;
use CognitioTechny\Contabil\Domain\Calendar\FiscalYear;
use CognitioTechny\Contabil\Domain\Calendar\Period;
use CognitioTechny\Contabil\Domain\Calendar\PeriodStatus;
use CognitioTechny\Contabil\Domain\Calendar\YearStatus;
use CognitioTechny\Contabil\Domain\Exception\ConflictException;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;
use DateTimeImmutable;

/**
 * @internal
 */
final class FiscalCalendarTest extends CIUnitTestCase
{
    public function testCalendarYearGeneratesTwelveGapFreePeriods(): void
    {
        [$year, $periods] = FiscalYear::start(1, new DateTimeImmutable('2026-01-01'));

        $this->assertSame('2026', $year->label);
        $this->assertSame('2026-12-31', $year->endDate->format('Y-m-d'));
        $this->assertSame(YearStatus::Open, $year->status);
        $this->assertCount(12, $periods);
        $this->assertGapFree($year, $periods);
        $this->assertSame('2026-02-28', $periods[1]->endDate->format('Y-m-d'));
    }

    public function testLeapYearFebruaryHasTwentyNineDays(): void
    {
        [, $periods] = FiscalYear::start(1, new DateTimeImmutable('2028-01-01'));

        $this->assertSame('2028-02-29', $periods[1]->endDate->format('Y-m-d'));
    }

    public function testYearNotStartingInJanuaryIsGapFreeAndLabelled(): void
    {
        [$year, $periods] = FiscalYear::start(1, new DateTimeImmutable('2026-07-01'));

        $this->assertSame('2026/2027', $year->label);
        $this->assertSame('2027-06-30', $year->endDate->format('Y-m-d'));
        $this->assertGapFree($year, $periods);
    }

    public function testStartMustBeTheFirstOfAMonth(): void
    {
        $this->expectException(ValidationException::class);

        FiscalYear::start(1, new DateTimeImmutable('2026-01-15'));
    }

    public function testLabelIsValidated(): void
    {
        [$year] = FiscalYear::start(1, new DateTimeImmutable('2026-01-01'), '  FY26 ');
        $this->assertSame('FY26', $year->label);

        $this->expectException(ValidationException::class);
        FiscalYear::start(1, new DateTimeImmutable('2026-01-01'), '   ');
    }

    public function testOverlapAndAdjacency(): void
    {
        [$y2026] = FiscalYear::start(1, new DateTimeImmutable('2026-01-01'));
        [$next]  = FiscalYear::start(1, new DateTimeImmutable('2027-01-01'));
        [$mid]   = FiscalYear::start(1, new DateTimeImmutable('2026-07-01'));

        $next->assertNoOverlap([$y2026]);
        $this->assertFalse($y2026->overlaps($next));
        $this->assertTrue($y2026->overlaps($mid));

        $this->expectException(ConflictException::class);
        $mid->assertNoOverlap([$y2026]);
    }

    public function testDuplicateLabelIsRejected(): void
    {
        [$a] = FiscalYear::start(1, new DateTimeImmutable('2026-01-01'), 'X');
        [$b] = FiscalYear::start(1, new DateTimeImmutable('2027-01-01'), 'X');

        $this->expectException(ConflictException::class);
        $b->assertNoOverlap([$a]);
    }

    public function testPeriodLifecycle(): void
    {
        $period = new Period(1, 1, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'));

        $closed = $period->close();
        $this->assertSame(PeriodStatus::Closed, $closed->status);
        $this->assertSame(PeriodStatus::Open, $closed->reopen()->status);
        $this->assertSame(PeriodStatus::Locked, $closed->lock()->status);
    }

    public function testPeriodTransitionsAreControlled(): void
    {
        $open   = new Period(1, 1, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'));
        $locked = $open->close()->lock();

        foreach ([
            static fn () => $open->reopen(),
            static fn () => $open->lock(),
            static fn () => $open->close()->close(),
            static fn () => $locked->reopen(),
            static fn () => $locked->close(),
        ] as $transition) {
            try {
                $transition();
                $this->fail('The transition must be rejected.');
            } catch (ConflictException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testPeriodContainsDate(): void
    {
        $period = new Period(null, null, new DateTimeImmutable('2026-02-01'), new DateTimeImmutable('2026-02-28'));

        $this->assertTrue($period->contains(new DateTimeImmutable('2026-02-28 23:59:59')));
        $this->assertFalse($period->contains(new DateTimeImmutable('2026-03-01')));
    }

    public function testYearClosesOnlyWhenEveryPeriodIsClosed(): void
    {
        [$year, $periods] = FiscalYear::start(1, new DateTimeImmutable('2026-01-01'));

        try {
            $year->close($periods);
            $this->fail('Open periods must block closing the year.');
        } catch (ConflictException) {
            $this->addToAssertionCount(1);
        }

        $closedPeriods = array_map(static fn (Period $p): Period => $p->close(), $periods);
        $closed        = $year->close($closedPeriods);

        $this->assertSame(YearStatus::Closed, $closed->status);
        $this->assertSame(YearStatus::Open, $closed->reopen()->status);

        $this->expectException(ConflictException::class);
        $closed->close($closedPeriods);
    }

    public function testOpenYearCannotBeReopened(): void
    {
        [$year] = FiscalYear::start(1, new DateTimeImmutable('2026-01-01'));

        $this->expectException(ConflictException::class);
        $year->reopen();
    }

    /**
     * @param list<Period> $periods
     */
    private function assertGapFree(FiscalYear $year, array $periods): void
    {
        $this->assertSame($year->startDate->format('Y-m-d'), $periods[0]->startDate->format('Y-m-d'));
        $this->assertSame($year->endDate->format('Y-m-d'), $periods[11]->endDate->format('Y-m-d'));

        for ($i = 1; $i < 12; $i++) {
            $this->assertSame(
                $periods[$i - 1]->endDate->modify('+1 day')->format('Y-m-d'),
                $periods[$i]->startDate->format('Y-m-d'),
                'Period ' . ($i + 1) . ' must start the day after period ' . $i . ' ends.',
            );
        }
    }
}
