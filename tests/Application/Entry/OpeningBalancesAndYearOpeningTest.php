<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Tests\Application\Entry;

use CodeIgniter\Test\CIUnitTestCase;
use CognitioTechny\Contabil\Application\Period\OpeningBalances;
use CognitioTechny\Contabil\Domain\Calendar\FiscalYear;
use CognitioTechny\Contabil\Domain\Calendar\PeriodStatus;
use CognitioTechny\Contabil\Domain\Calendar\YearStatus;
use CognitioTechny\Contabil\Domain\Entry\EntryStatus;
use CognitioTechny\Contabil\Domain\Entry\Side;
use CognitioTechny\Contabil\Domain\Event\FiscalYearOpened;
use CognitioTechny\Contabil\Domain\Event\OpeningBalancesRecorded;
use CognitioTechny\Contabil\Domain\Exception\ConflictException;
use CognitioTechny\Contabil\Domain\Exception\NotFoundException;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;
use DateTimeImmutable;
use Tests\Support\LedgerKit;

/**
 * Opening balances of the first year, and the opening of the next years.
 *
 * @internal
 */
final class OpeningBalancesAndYearOpeningTest extends CIUnitTestCase
{
    private LedgerKit $kit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kit = (new LedgerKit('3.2.01'))->standard();
    }

    private function yearId(int $index = 0): int
    {
        return (int) $this->kit->years->forEntity(LedgerKit::ENTITY)[$index]->id;
    }

    /**
     * @param list<array{string, string, int}> $rows Account code, D or C, amount.
     *
     * @return list<array<string, mixed>>
     */
    private function balances(array $rows): array
    {
        return array_map(static fn (array $r): array => ['account_code' => $r[0], 'side' => $r[1], 'amount' => $r[2]], $rows);
    }

    private function balanceOf(string $code, string $asOf): int
    {
        $rows = $this->kit->trialBalance->execute(LedgerKit::ENTITY, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable($asOf))->rows;

        foreach ($rows as $row) {
            if ($row['code'] === $code) {
                return $row['closing'];
            }
        }

        return 0;
    }

    public function testOpeningBalancesArePostedOnTheFirstDayOfTheYear(): void
    {
        $entry = $this->kit->openingBalances->record(LedgerKit::ENTITY, $this->yearId(), $this->balances([
            ['1.1.01', 'D', 150000],
            ['1.1.02', 'D', 50000],
            ['2.1.01', 'C', 30000],
            ['3.1.01', 'C', 170000],
        ]));

        $this->assertSame(EntryStatus::Posted, $entry->status);
        $this->assertSame('2026-01-01', $entry->accrualDate->format('Y-m-d'));
        $this->assertSame(OpeningBalances::SOURCE, $entry->sourceType);
        $this->assertSame((string) $this->yearId(), $entry->sourceId);
        $this->assertSame(1, $entry->number);
        $this->assertSame(200000, $entry->totalDebits());
        $this->assertSame('Opening balances of fiscal year 2026', $entry->description);
        $this->assertSame(['EntryPosted', 'OpeningBalancesRecorded'], $this->kit->dispatcher->names());

        $this->assertSame(150000, $this->balanceOf('1.1.01', '2026-01-31'));
        $this->assertSame(-170000, $this->balanceOf('3.1.01', '2026-01-31'));
        $this->assertSame($entry->id, $this->kit->openingBalances->current(LedgerKit::ENTITY, $this->yearId())?->id);
    }

    public function testAccountsCanBeGivenByIdAndZeroBalancesAreSkipped(): void
    {
        $entry = $this->kit->openingBalances->record(LedgerKit::ENTITY, $this->yearId(), [
            ['account_id' => $this->kit->ids['1.1.01'], 'side' => 'D', 'amount' => 900],
            ['account_code' => '1.1.02', 'side' => 'D', 'amount' => 0],
            ['account_code' => '3.1.01', 'side' => 'credit', 'amount' => 900],
        ]);

        $this->assertCount(2, $entry->lines);
    }

    public function testUnbalancedOpeningBalancesNeedABalancingAccount(): void
    {
        $rows = $this->balances([['1.1.01', 'D', 1000], ['2.1.01', 'C', 400]]);

        try {
            $this->kit->openingBalances->record(LedgerKit::ENTITY, $this->yearId(), $rows);
            $this->fail('Unbalanced opening balances are refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('debits exceed credits by 6.00', $e->getMessage());
        }

        $entry = $this->kit->openingBalances->record(LedgerKit::ENTITY, $this->yearId(), $rows, $this->kit->ids['3.1.01']);

        $balancing = $entry->lines[array_key_last($entry->lines)];
        $this->assertSame([$this->kit->ids['3.1.01'], Side::Credit, 600], [$balancing->accountId, $balancing->side, $balancing->amount]);
        $this->assertTrue($entry->isBalanced());
    }

    public function testTheBalancingAccountMustBeEquity(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must be an active equity account');

        $this->kit->openingBalances->record(LedgerKit::ENTITY, $this->yearId(), $this->balances([['1.1.01', 'D', 1000]]), $this->kit->ids['2.1.01']);
    }

    public function testInvalidBalancesAreRefusedWithTheirPosition(): void
    {
        $cases = [
            'revenue'   => [$this->balances([['1.1.01', 'D', 10], ['4.1.01', 'C', 10]]), 'Balance 2: account 4.1.01 is a revenue account'],
            'duplicate' => [$this->balances([['1.1.01', 'D', 10], ['1.1.01', 'C', 10]]), 'Balance 2: account 1.1.01 appears more than once'],
            'negative'  => [$this->balances([['1.1.01', 'D', -10], ['3.1.01', 'C', 10]]), 'Balance 1: amount'],
            'side'      => [$this->balances([['1.1.01', 'X', 10], ['3.1.01', 'C', 10]]), 'Balance 1: The side'],
            'unknown'   => [$this->balances([['9.9.99', 'D', 10], ['3.1.01', 'C', 10]]), 'Balance 1: the account does not exist'],
            'empty'     => [$this->balances([['1.1.01', 'D', 0]]), 'No opening balance'],
            'group'     => [$this->balances([['1', 'D', 10], ['3.1.01', 'C', 10]]), 'is a group'],
        ];

        foreach ($cases as $name => [$rows, $message]) {
            try {
                $this->kit->openingBalances->record(LedgerKit::ENTITY, $this->yearId(), $rows);
                $this->fail($name . ' should be refused.');
            } catch (ValidationException $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $name);
            }
        }

        $this->assertNull($this->kit->openingBalances->current(LedgerKit::ENTITY, $this->yearId()));
    }

    public function testOpeningBalancesArePostedOnceUnlessReplaced(): void
    {
        $first = $this->kit->openingBalances->record(LedgerKit::ENTITY, $this->yearId(), $this->balances([['1.1.01', 'D', 1000], ['3.1.01', 'C', 1000]]));

        try {
            $this->kit->openingBalances->record(LedgerKit::ENTITY, $this->yearId(), $this->balances([['1.1.01', 'D', 1200], ['3.1.01', 'C', 1200]]));
            $this->fail('A second opening needs replace.');
        } catch (ConflictException $e) {
            $this->assertStringContainsString('already posted as entry 1', $e->getMessage());
        }

        $this->kit->dispatcher->events = [];
        $second                        = $this->kit->openingBalances->record(LedgerKit::ENTITY, $this->yearId(), $this->balances([['1.1.01', 'D', 1200], ['3.1.01', 'C', 1200]]), null, 'Corrected opening', true);

        $this->assertSame('Corrected opening', $second->description);
        $this->assertSame(EntryStatus::Reversed, $this->kit->queries->get(LedgerKit::ENTITY, (int) $first->id)->status);
        $this->assertSame(1200, $this->balanceOf('1.1.01', '2026-01-31'));
        $this->assertSame(['EntryPosted', 'EntryReversed', 'EntryPosted', 'OpeningBalancesRecorded'], $this->kit->dispatcher->names());

        $event = $this->kit->dispatcher->events[3];
        $this->assertInstanceOf(OpeningBalancesRecorded::class, $event);
        $this->assertSame($first->id, $event->replaced?->id);
        $this->assertSame($second->id, $this->kit->openingBalances->current(LedgerKit::ENTITY, $this->yearId())?->id);
    }

    public function testReversingTheOpeningEntryAllowsANewOne(): void
    {
        $first = $this->kit->openingBalances->record(LedgerKit::ENTITY, $this->yearId(), $this->balances([['1.1.01', 'D', 1000], ['3.1.01', 'C', 1000]]));
        $this->kit->reverse->execute(LedgerKit::ENTITY, (int) $first->id, 'Wrong figures');

        $this->assertNull($this->kit->openingBalances->current(LedgerKit::ENTITY, $this->yearId()));

        $this->kit->openingBalances->record(LedgerKit::ENTITY, $this->yearId(), $this->balances([['1.1.01', 'D', 700], ['3.1.01', 'C', 700]]));
        $this->assertSame(700, $this->balanceOf('1.1.01', '2026-01-31'));
    }

    public function testOpeningBalancesGoInTheFirstOpenYearOnly(): void
    {
        [$next] = $this->kit->fiscalYears->openNext(LedgerKit::ENTITY, $this->yearId());

        try {
            $this->kit->openingBalances->record(LedgerKit::ENTITY, (int) $next->id, $this->balances([['1.1.01', 'D', 10], ['3.1.01', 'C', 10]]));
            $this->fail('Only the first year takes opening balances.');
        } catch (ConflictException $e) {
            $this->assertStringContainsString('first fiscal year of the entity ("2026")', $e->getMessage());
        }

        $this->kit->periods->close(LedgerKit::ENTITY, (int) $this->kit->years->periodsOf($this->yearId())[0]->id);

        try {
            $this->kit->openingBalances->record(LedgerKit::ENTITY, $this->yearId(), $this->balances([['1.1.01', 'D', 10], ['3.1.01', 'C', 10]]));
            $this->fail('A closed first period accepts no opening.');
        } catch (ConflictException $e) {
            $this->assertStringContainsString('accepts no entries', $e->getMessage());
        }

        $this->expectException(NotFoundException::class);
        $this->kit->openingBalances->record(LedgerKit::ENTITY, 999, []);
    }

    public function testOpeningTheNextYearCreatesItsTwelvePeriods(): void
    {
        [$next, $periods] = $this->kit->fiscalYears->openNext(LedgerKit::ENTITY, $this->yearId());

        $this->assertSame('2027', $next->label);
        $this->assertSame(YearStatus::Open, $next->status);
        $this->assertSame(['2027-01-01', '2027-12-31'], [$next->startDate->format('Y-m-d'), $next->endDate->format('Y-m-d')]);
        $this->assertCount(12, $periods);
        $this->assertSame(PeriodStatus::Open, $periods[0]->status);

        $event = $this->kit->dispatcher->events[0];
        $this->assertInstanceOf(FiscalYearOpened::class, $event);
        $this->assertSame('2026', $event->previous->label);
        $this->assertSame('u1', $event->userId);

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('already open as "2027"');
        $this->kit->fiscalYears->openNext(LedgerKit::ENTITY, $this->yearId());
    }

    public function testOpeningTheNextYearTakesALabelAndRefusesOverlaps(): void
    {
        [$next] = $this->kit->fiscalYears->openNext(LedgerKit::ENTITY, $this->yearId(), 'FY27');
        $this->assertSame('FY27', $next->label);

        // A year created by hand halfway through 2028 blocks the opening of 2028.
        $this->kit->years->insert(...FiscalYear::start(LedgerKit::ENTITY, new DateTimeImmutable('2028-07-01')));

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('overlaps');
        $this->kit->fiscalYears->openNext(LedgerKit::ENTITY, (int) $next->id);
    }

    public function testBalanceSheetAccountsCarryIntoTheNextYearAndTheResultIsClosed(): void
    {
        $this->kit->openingBalances->record(LedgerKit::ENTITY, $this->yearId(), $this->balances([['1.1.01', 'D', 100000], ['3.1.01', 'C', 100000]]));
        $this->kit->posted('2026-05-10', 'Sale', [['1.1.01', 'D', 40000], ['4.1.01', 'C', 40000]]);
        $this->kit->posted('2026-06-10', 'Rent', [['5.1.01', 'D', 15000], ['1.1.01', 'C', 15000]]);

        [$next] = $this->kit->fiscalYears->openNext(LedgerKit::ENTITY, $this->yearId());

        // The next year takes entries while the previous one is still open.
        $this->kit->posted('2027-01-15', 'Sale', [['1.1.02', 'D', 5000], ['4.1.01', 'C', 5000]]);

        foreach (array_slice($this->kit->years->periodsOf($this->yearId()), 0, 11) as $period) {
            $this->kit->periods->close(LedgerKit::ENTITY, (int) $period->id);
        }

        $this->kit->fiscalYears->close(LedgerKit::ENTITY, $this->yearId());

        $tb   = $this->kit->trialBalance->execute(LedgerKit::ENTITY, new DateTimeImmutable('2027-01-01'), new DateTimeImmutable('2027-12-31'));
        $rows = array_column($tb->rows, null, 'code');

        $this->assertSame(125000, $rows['1.1.01']['opening'], 'cash carries 100000 + 40000 - 15000');
        $this->assertSame(-100000, $rows['3.1.01']['opening']);
        $this->assertSame(-25000, $rows['3.2.01']['opening'], 'the 2026 result sits in equity');
        $this->assertSame(0, $rows['4.1.01']['opening'], 'revenue starts the year at zero');
        $this->assertSame(-5000, $rows['4.1.01']['closing']);
        $this->assertTrue($tb->balanced);
        $this->assertSame(YearStatus::Open, $this->kit->years->find(LedgerKit::ENTITY, (int) $next->id)?->status);
    }
}
