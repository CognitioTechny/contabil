<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Tests\Application\Entry;

use CodeIgniter\Test\CIUnitTestCase;
use CognitioTechny\Contabil\Application\Period\ManageFiscalYear;
use CognitioTechny\Contabil\Domain\Calendar\FiscalYear;
use CognitioTechny\Contabil\Domain\Calendar\PeriodStatus;
use CognitioTechny\Contabil\Domain\Calendar\YearStatus;
use CognitioTechny\Contabil\Domain\Entry\EntryStatus;
use CognitioTechny\Contabil\Domain\Entry\Side;
use CognitioTechny\Contabil\Domain\Exception\ConflictException;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;
use DateTimeImmutable;
use Tests\Support\LedgerKit;

/**
 * Closing and reopening periods and fiscal years.
 *
 * @internal
 */
final class PeriodAndYearClosingTest extends CIUnitTestCase
{
    private LedgerKit $kit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kit = (new LedgerKit('3.2.01'))->standard();
    }

    private function periodId(string $date): int
    {
        return (int) $this->kit->years->periodContaining(LedgerKit::ENTITY, new DateTimeImmutable($date))[1]->id;
    }

    private function yearId(): int
    {
        return (int) $this->kit->years->forEntity(LedgerKit::ENTITY)[0]->id;
    }

    private function closeAllButLast(): void
    {
        foreach (range(1, 11) as $month) {
            $this->kit->periods->close(LedgerKit::ENTITY, $this->periodId(sprintf('2026-%02d-15', $month)));
        }
    }

    public function testClosingNeedsEveryDraftResolved(): void
    {
        $draft = $this->kit->create->execute(LedgerKit::ENTITY, $this->kit->entry('2026-03-02', 'Pending', [['1.1.02', 'D', 5], ['4.1.01', 'C', 5]]))->entry;

        try {
            $this->kit->periods->close(LedgerKit::ENTITY, $this->periodId('2026-03-10'));
            $this->fail('A period with drafts cannot close.');
        } catch (ConflictException $e) {
            $this->assertStringContainsString('1 draft', $e->getMessage());
        }

        $this->kit->post->execute(LedgerKit::ENTITY, (int) $draft->id);
        $closed = $this->kit->periods->close(LedgerKit::ENTITY, $this->periodId('2026-03-10'));

        $this->assertSame(PeriodStatus::Closed, $closed->status);
        $this->assertSame(['EntryPosted', 'PeriodClosed'], $this->kit->dispatcher->names());
    }

    public function testClosedPeriodCannotBeClosedAgain(): void
    {
        $id = $this->periodId('2026-03-10');
        $this->kit->periods->close(LedgerKit::ENTITY, $id);

        $this->expectException(ConflictException::class);
        $this->kit->periods->close(LedgerKit::ENTITY, $id);
    }

    public function testReopeningNeedsAReasonAndEmitsAnAuditEvent(): void
    {
        $id = $this->periodId('2026-03-10');
        $this->kit->periods->close(LedgerKit::ENTITY, $id);

        try {
            $this->kit->periods->reopen(LedgerKit::ENTITY, $id, ' ');
            $this->fail('A reason is required.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $reopened = $this->kit->periods->reopen(LedgerKit::ENTITY, $id, 'Missing invoice');

        $this->assertSame(PeriodStatus::Open, $reopened->status);
        $event = $this->kit->dispatcher->events[array_key_last($this->kit->dispatcher->events)];
        $this->assertSame('Missing invoice', $event->reason);
        $this->assertSame('u1', $event->userId);

        $this->kit->posted('2026-03-10', 'Now accepted', [['1.1.02', 'D', 5], ['4.1.01', 'C', 5]]);
    }

    public function testYearClosingMovesTheResultToEquityAndClosesEverything(): void
    {
        $this->kit->posted('2026-01-05', 'Capital', [['1.1.01', 'D', 100000], ['3.1.01', 'C', 100000]]);
        $this->kit->posted('2026-02-05', 'Sale', [['1.1.02', 'D', 50000], ['4.1.01', 'C', 50000]]);
        $this->kit->posted('2026-02-06', 'Return', [['4.9.01', 'D', 5000], ['1.1.02', 'C', 5000]]);
        $this->kit->posted('2026-03-05', 'Rent', [['5.1.01', 'D', 12000], ['1.1.01', 'C', 12000]]);
        $this->closeAllButLast();

        [$year, $closing] = $this->kit->fiscalYears->close(LedgerKit::ENTITY, $this->yearId());

        $this->assertSame(YearStatus::Closed, $year->status);
        $this->assertNotNull($closing);
        $this->assertSame('2026-12-31', $closing->accrualDate->format('Y-m-d'));
        $this->assertSame(ManageFiscalYear::CLOSING_SOURCE, $closing->sourceType);

        // Revenue 50000 - returns 5000 - rent 12000 = 33000 profit, credited to 3.2.01.
        $result = array_values(array_filter($closing->lines, fn ($l): bool => $l->accountId === $this->kit->ids['3.2.01']));
        $this->assertCount(1, $result);
        $this->assertSame([Side::Credit, 33000], [$result[0]->side, $result[0]->amount]);

        foreach (['4.1.01', '4.9.01', '5.1.01'] as $code) {
            $sums = $this->kit->ledger->accrualSums(LedgerKit::ENTITY, null, null)[$this->kit->ids[$code]];
            $this->assertSame($sums[0], $sums[1], $code . ' is zeroed');
        }

        $this->assertSame(PeriodStatus::Closed, $this->kit->years->periodsOf($this->yearId())[11]->status);
        $this->assertContains('FiscalYearClosed', $this->kit->dispatcher->names());
    }

    public function testYearClosingBooksALossToTheDebitSide(): void
    {
        $this->kit->posted('2026-03-05', 'Rent', [['5.1.01', 'D', 700], ['1.1.01', 'C', 700]]);
        $this->closeAllButLast();

        [, $closing] = $this->kit->fiscalYears->close(LedgerKit::ENTITY, $this->yearId());

        $result = array_values(array_filter($closing->lines, fn ($l): bool => $l->accountId === $this->kit->ids['3.2.01']));
        $this->assertSame([Side::Debit, 700], [$result[0]->side, $result[0]->amount]);
    }

    public function testYearWithNoResultClosesWithoutAnEntry(): void
    {
        $this->kit->posted('2026-01-05', 'Capital', [['1.1.01', 'D', 100], ['3.1.01', 'C', 100]]);
        $this->closeAllButLast();

        [$year, $closing] = $this->kit->fiscalYears->close(LedgerKit::ENTITY, $this->yearId());

        $this->assertSame(YearStatus::Closed, $year->status);
        $this->assertNull($closing);
    }

    public function testYearClosingNeedsPreviousPeriodsClosed(): void
    {
        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('before closing the year');
        $this->kit->fiscalYears->close(LedgerKit::ENTITY, $this->yearId());
    }

    public function testYearClosingNeedsAResultAccount(): void
    {
        $kit = (new LedgerKit())->standard();
        $kit->posted('2026-03-05', 'Rent', [['5.1.01', 'D', 700], ['1.1.01', 'C', 700]]);

        foreach (range(1, 11) as $month) {
            $kit->periods->close(LedgerKit::ENTITY, (int) $kit->years->periodContaining(LedgerKit::ENTITY, new DateTimeImmutable(sprintf('2026-%02d-15', $month)))[1]->id);
        }

        try {
            $kit->fiscalYears->close(LedgerKit::ENTITY, (int) $kit->years->forEntity(LedgerKit::ENTITY)[0]->id);
            $this->fail('A result account is needed.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('result account', $e->getMessage());
        }

        // Passing it explicitly works and the failed attempt left nothing behind.
        [$year] = $kit->fiscalYears->close(LedgerKit::ENTITY, (int) $kit->years->forEntity(LedgerKit::ENTITY)[0]->id, $kit->ids['3.2.01']);
        $this->assertSame(YearStatus::Closed, $year->status);
    }

    public function testResultAccountMustBeEquity(): void
    {
        $this->kit->posted('2026-03-05', 'Rent', [['5.1.01', 'D', 700], ['1.1.01', 'C', 700]]);
        $this->closeAllButLast();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('equity');
        $this->kit->fiscalYears->close(LedgerKit::ENTITY, $this->yearId(), $this->kit->ids['1.1.01']);
    }

    public function testFailedYearClosingRollsBackTheClosingEntry(): void
    {
        $this->kit->posted('2026-03-05', 'Rent', [['5.1.01', 'D', 700], ['1.1.01', 'C', 700]]);
        $this->closeAllButLast();

        // The last period is closed: the closing entry cannot be posted.
        $this->kit->periods->close(LedgerKit::ENTITY, $this->periodId('2026-12-15'));

        try {
            $this->kit->fiscalYears->close(LedgerKit::ENTITY, $this->yearId());
            $this->fail('The closing entry needs an open last period.');
        } catch (ConflictException $e) {
            $this->assertStringContainsString('reopen', $e->getMessage());
        }

        $this->assertSame(YearStatus::Open, $this->kit->years->forEntity(LedgerKit::ENTITY)[0]->status);
    }

    public function testReopeningTheYearReversesTheClosingEntry(): void
    {
        $this->kit->posted('2026-03-05', 'Rent', [['5.1.01', 'D', 700], ['1.1.01', 'C', 700]]);
        $this->closeAllButLast();
        [, $closing] = $this->kit->fiscalYears->close(LedgerKit::ENTITY, $this->yearId());

        $year = $this->kit->fiscalYears->reopen(LedgerKit::ENTITY, $this->yearId(), 'Auditor adjustment');

        $this->assertSame(YearStatus::Open, $year->status);
        $this->assertSame(EntryStatus::Reversed, $this->kit->queries->get(LedgerKit::ENTITY, (int) $closing->id)->status);
        $this->assertSame(PeriodStatus::Open, $this->kit->years->periodsOf($this->yearId())[11]->status);

        $rent = $this->kit->ledger->accrualSums(LedgerKit::ENTITY, null, null)[$this->kit->ids['5.1.01']];
        $this->assertSame(700, $rent[0] - $rent[1], 'the expense is back after the closing is reversed');

        // And the year can be closed again, with a new closing entry.
        [, $again] = $this->kit->fiscalYears->close(LedgerKit::ENTITY, $this->yearId());
        $this->assertNotSame($closing->id, $again->id);
    }

    public function testYearCannotBeReopenedWhileTheNextYearIsClosed(): void
    {
        $this->kit->years->insert(...FiscalYear::start(LedgerKit::ENTITY, new DateTimeImmutable('2027-01-01')));
        $this->closeAllButLast();
        $this->kit->fiscalYears->close(LedgerKit::ENTITY, $this->yearId());

        $next = $this->kit->years->forEntity(LedgerKit::ENTITY)[1];

        foreach ($this->kit->years->periodsOf((int) $next->id) as $period) {
            if ($period->id !== $this->kit->years->periodsOf((int) $next->id)[11]->id) {
                $this->kit->periods->close(LedgerKit::ENTITY, (int) $period->id);
            }
        }

        $this->kit->fiscalYears->close(LedgerKit::ENTITY, (int) $next->id);

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('after this one is closed');
        $this->kit->fiscalYears->reopen(LedgerKit::ENTITY, $this->yearId(), 'Too late');
    }

    public function testClosedYearBlocksReopeningItsPeriods(): void
    {
        $this->closeAllButLast();
        $this->kit->fiscalYears->close(LedgerKit::ENTITY, $this->yearId());

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('reopen the year first');
        $this->kit->periods->reopen(LedgerKit::ENTITY, $this->periodId('2026-02-10'), 'Fix');
    }

    public function testOnlyAClosedYearCanBeReopened(): void
    {
        $this->expectException(ConflictException::class);
        $this->kit->fiscalYears->reopen(LedgerKit::ENTITY, $this->yearId(), 'No need');
    }
}
