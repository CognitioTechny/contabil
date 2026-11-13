<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Tests\Infrastructure;

use CognitioTechny\Contabil\Application\Entry\EntryData;
use CognitioTechny\Contabil\Domain\Calendar\PeriodStatus;
use CognitioTechny\Contabil\Domain\Calendar\YearStatus;
use CognitioTechny\Contabil\Domain\Entry\EntryFilter;
use CognitioTechny\Contabil\Domain\Entry\EntryStatus;
use CognitioTechny\Contabil\Domain\Exception\ContabilException;
use DateTimeImmutable;
use Tests\Support\DbLedgerTestCase;

/**
 * Two entities in the same database: whatever entity A does, it can never read or change entity B, even when it
 * is handed B's ids. Hosts that serve many offices from one database (one entity per office) rely on this.
 *
 * Entity A comes from the base class (chart + 2026 calendar); entity B is built here with its own chart, year and
 * a posted entry.
 *
 * @internal
 */
final class EntityIsolationDatabaseTest extends DbLedgerTestCase
{
    private int $other;

    /**
     * @var array<string, int> B's accounts by code
     */
    private array $otherIds = [];

    private int $otherEntryId;

    private int $otherYearId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->other = $this->uc()->createEntity()->execute('Beta');
        $this->uc()->createFiscalYear()->execute($this->other, '2026-01-01');
        $this->otherYearId = (int) $this->uc()->listFiscalYears()->execute($this->other)[0]->id;

        foreach ([
            ['1', 'Assets', 'ASSET', null, false],
            ['1.1.01', 'Cash', 'ASSET', '1', true],
            ['4', 'Revenue', 'REVENUE', null, false],
            ['4.1.01', 'Sales', 'REVENUE', '4', false],
        ] as [$code, $name, $type, $parent, $cash]) {
            $this->otherIds[$code] = (int) $this->uc()->createAccount()->execute(
                $this->other,
                $code,
                $name,
                $type,
                $parent === null ? null : $this->otherIds[$parent],
                false,
                null,
                $cash,
            )->id;
        }

        $this->otherEntryId = (int) $this->uc()->createEntry()->execute($this->other, new EntryData(
            '2026-03-02',
            'Beta sale',
            [
                ['account_id' => $this->otherIds['1.1.01'], 'side' => 'D', 'amount' => 1500],
                ['account_id' => $this->otherIds['4.1.01'], 'side' => 'C', 'amount' => 1500],
            ],
            null,
            null,
            null,
            'shared-key',
        ), true)->entry->id;
    }

    /**
     * Runs `$call`, which must refuse (any package exception), and checks that B's data did not change.
     *
     * @param callable(): mixed $call
     */
    private function assertRefused(callable $call, string $what): void
    {
        try {
            $call();
            $this->fail($what . ' should have been refused for another entity.');
        } catch (ContabilException) {
            $this->addToAssertionCount(1);
        }

        $this->assertOtherUntouched();
    }

    private function assertOtherUntouched(): void
    {
        $entry = $this->uc()->entries()->get($this->other, $this->otherEntryId);
        $this->assertSame(EntryStatus::Posted, $entry->status);
        $this->assertSame('Beta sale', $entry->description);

        $this->assertSame('Cash', $this->uc()->getAccount()->execute($this->other, $this->otherIds['1.1.01'])->name);
        $this->assertCount(4, $this->uc()->listAccounts()->execute($this->other));
    }

    public function testAccountsOfAnotherEntityAreInvisibleAndImmutable(): void
    {
        $theirs = $this->otherIds['1.1.01'];

        $this->assertRefused(fn () => $this->uc()->getAccount()->execute($this->entity, $theirs), 'reading an account');
        $this->assertRefused(fn () => $this->uc()->updateAccount()->execute($this->entity, $theirs, ['name' => 'Hijacked']), 'updating an account');
        $this->assertRefused(fn () => $this->uc()->deactivateAccount()->execute($this->entity, $theirs), 'deactivating an account');
        $this->assertRefused(fn () => $this->uc()->deleteAccount()->execute($this->entity, $theirs), 'deleting an account');
        $this->assertRefused(fn () => $this->uc()->moveAccount()->execute($this->entity, $this->ids['5.1.01'], $this->otherIds['1']), 'moving under a parent of another entity');

        $listed = array_map(static fn ($a): int => (int) $a->id, $this->uc()->listAccounts()->execute($this->entity));
        $this->assertSame([], array_intersect($listed, array_values($this->otherIds)));
    }

    public function testEntriesOfAnotherEntityAreInvisibleAndImmutable(): void
    {
        $theirs = $this->otherEntryId;

        $this->assertRefused(fn () => $this->uc()->entries()->get($this->entity, $theirs), 'reading an entry');
        $this->assertRefused(fn () => $this->uc()->reverseEntry()->execute($this->entity, $theirs, 'oops'), 'reversing an entry');
        $this->assertRefused(fn () => $this->uc()->postEntry()->execute($this->entity, $theirs), 'posting an entry');
        $this->assertRefused(fn () => $this->uc()->manageDraft()->delete($this->entity, $theirs), 'deleting a draft');

        $mine   = $this->posted('2026-03-05', 'Alpha sale', [['1.1.02', 'D', 700], ['4.1.01', 'C', 700]]);
        $listed = array_map(static fn ($e): int => (int) $e->id, $this->uc()->entries()->list($this->entity, new EntryFilter()));
        $this->assertSame([(int) $mine->id], $listed);

        $byAccount = $this->uc()->entries()->list($this->entity, new EntryFilter(accountId: $this->otherIds['1.1.01']));
        $this->assertSame([], $byAccount, 'filtering by an account of another entity finds nothing');
    }

    public function testAnEntryCannotUseAccountsOfAnotherEntity(): void
    {
        $this->assertRefused(
            fn () => $this->uc()->createEntry()->execute($this->entity, new EntryData(
                '2026-03-06',
                'Mixed',
                [
                    ['account_id' => $this->ids['1.1.01'], 'side' => 'D', 'amount' => 100],
                    ['account_id' => $this->otherIds['4.1.01'], 'side' => 'C', 'amount' => 100],
                ],
            ), true),
            'an entry with an account of another entity',
        );

        $this->assertSame(0, $this->db->table('acc_entries')->where('entity_id', $this->entity)->countAllResults());
    }

    public function testTheSameIdempotencyKeyIsIndependentPerEntity(): void
    {
        $mine = $this->uc()->createEntry()->execute($this->entity, $this->data('2026-03-02', 'Alpha sale', [['1.1.02', 'D', 900], ['4.1.01', 'C', 900]], null, 'shared-key'), true);

        $this->assertTrue($mine->created, 'B already used "shared-key"; A is not affected');
        $this->assertNotSame($this->otherEntryId, (int) $mine->entry->id);
        $this->assertSame(900, $mine->entry->lines[0]->amount);
        $this->assertSame(1, $mine->entry->number, 'numbering is per entity');
        $this->assertSame(1, $mine->entry->chainPosition, 'the hash chain is per entity');
    }

    public function testCalendarOfAnotherEntityIsInvisibleAndImmutable(): void
    {
        $this->assertRefused(fn () => $this->uc()->listPeriods()->execute($this->entity, $this->otherYearId), 'listing periods of a year of another entity');

        $theirPeriod = (int) $this->uc()->listPeriods()->execute($this->other, $this->otherYearId)[0]->id;
        $this->assertRefused(fn () => $this->uc()->managePeriod()->close($this->entity, $theirPeriod), 'closing a period');
        $this->assertRefused(fn () => $this->uc()->manageFiscalYear()->close($this->entity, $this->otherYearId), 'closing a fiscal year');

        $years = array_map(static fn ($y): int => (int) $y->id, $this->uc()->listFiscalYears()->execute($this->entity));
        $this->assertNotContains($this->otherYearId, $years);

        $this->assertSame(PeriodStatus::Open, $this->uc()->listPeriods()->execute($this->other, $this->otherYearId)[0]->status);
        $this->assertSame(YearStatus::Open, $this->uc()->listFiscalYears()->execute($this->other)[0]->status);
    }

    public function testPostingRulesStayInsideTheirEntity(): void
    {
        $this->assertRefused(
            fn () => $this->uc()->postingRules()->register($this->entity, 'sale', $this->otherIds['1.1.01'], $this->otherIds['4.1.01']),
            'a rule using accounts of another entity',
        );

        $theirs = $this->uc()->postingRules()->register($this->other, 'sale', $this->otherIds['1.1.01'], $this->otherIds['4.1.01']);
        $this->assertRefused(fn () => $this->uc()->postingRules()->setActive($this->entity, (int) $theirs->id, false), 'changing a rule');
        $this->assertSame([], $this->uc()->postingRules()->list($this->entity));
        $this->assertTrue($this->uc()->postingRules()->list($this->other)[0]->active);
    }

    public function testReportsOnlyShowTheirOwnEntity(): void
    {
        $from = new DateTimeImmutable('2026-01-01');
        $to   = new DateTimeImmutable('2026-12-31');

        $empty = $this->uc()->trialBalance()->execute($this->entity, $from, $to);
        $this->assertSame([], $empty->rows, 'A has no entries: nothing of B shows up');
        $this->assertSame(0, $empty->totals['debits']);

        $beta = $this->uc()->trialBalance()->execute($this->other, $from, $to);
        $this->assertSame(1500, $beta->totals['debits']);

        $this->assertRefused(fn () => $this->uc()->generalLedger()->forAccount($this->entity, $this->otherIds['1.1.01'], $from, $to), 'a ledger of an account of another entity');
        $this->assertSame([], $this->uc()->entryListing()->journal($this->entity, $from, $to)->entries);
        $this->assertSame([], $this->uc()->entryListing()->bySource($this->entity, 'x', 'y')->entries);
    }

    public function testSettlementsCannotCrossEntities(): void
    {
        $mine = $this->posted('2026-03-05', 'Alpha sale', [['1.1.02', 'D', 700], ['4.1.01', 'C', 700]]);

        $this->assertRefused(fn () => $this->uc()->settleEntry()->execute($this->entity, (int) $mine->id, $this->otherEntryId, 100), 'settling an entry of another entity');
        $this->assertRefused(fn () => $this->uc()->settleEntry()->execute($this->entity, $this->otherEntryId, (int) $mine->id, 100), 'settling with an entry of another entity');
    }

    public function testMaintenanceOfOneEntityDoesNotTouchTheOther(): void
    {
        $this->posted('2026-03-05', 'Alpha sale', [['1.1.02', 'D', 700], ['4.1.01', 'C', 700]]);

        $before = $this->db->table('acc_balances')->whereIn('account_id', array_values($this->otherIds))->orderBy('account_id')->orderBy('period_id')->get()->getResultArray();
        $this->assertNotEmpty($before);

        $this->uc()->recalculateBalances()->execute($this->entity);

        $after = $this->db->table('acc_balances')->whereIn('account_id', array_values($this->otherIds))->orderBy('account_id')->orderBy('period_id')->get()->getResultArray();
        $this->assertSame(array_column($before, 'closing_balance'), array_column($after, 'closing_balance'));
        $this->assertCount(count($before), $after, 'B keeps all its balance rows');

        $report = $this->uc()->integrity()->verify($this->entity);
        $this->assertTrue($report->isIntact());
        $this->assertSame(1, $report->checked, 'only A\'s entries are checked');
        $this->assertSame(1, $this->uc()->integrity()->verify($this->other)->checked);
    }
}
