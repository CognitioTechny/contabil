<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Tests\Infrastructure;

use CognitioTechny\Contabil\Domain\Entry\EntryFilter;
use CognitioTechny\Contabil\Domain\Entry\EntryHasher;
use CognitioTechny\Contabil\Domain\Entry\EntryStatus;
use CognitioTechny\Contabil\Domain\Exception\ConflictException;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;
use DateTimeImmutable;
use Tests\Support\DbLedgerTestCase;

/**
 * The entry engine against a real database: storage, numbering, the hash
 * chain, the balance cache and period handling.
 *
 * @internal
 */
final class EntryDatabaseTest extends DbLedgerTestCase
{
    private function periodId(string $date): int
    {
        return (int) $this->uc()->listPeriods()->execute($this->entity, 1)[(int) substr($date, 5, 2) - 1]->id;
    }

    /**
     * @return array<string, array{int, int, int, int}> "account:period" => opening, debits, credits, closing.
     */
    private function balances(): array
    {
        $rows = $this->db->table('acc_balances')->orderBy('account_id')->orderBy('period_id')->get()->getResultArray();
        $map  = [];

        foreach ($rows as $row) {
            $map[$row['account_id'] . ':' . $row['period_id']] = [(int) $row['opening_balance'], (int) $row['total_debits'], (int) $row['total_credits'], (int) $row['closing_balance']];
        }

        return $map;
    }

    public function testPostsAndReadsBackAnEntryWithItsLines(): void
    {
        $entry = $this->posted('2026-03-02', 'Sale', [['1.1.02', 'D', 1500], ['4.1.01', 'C', 1500]], null, 'erp:sale:1:1');

        $this->assertSame(1, $entry->number);
        $this->assertSame(EntryStatus::Posted, $entry->status);
        $this->assertSame(1, $entry->chainPosition);

        $stored = $this->uc()->entries()->get($this->entity, (int) $entry->id);

        $this->assertSame($entry->hash, $stored->hash);
        $this->assertCount(2, $stored->lines);
        $this->assertSame([1500, 1500], array_map(static fn ($l): int => $l->amount, $stored->lines));
        $this->assertTrue(EntryHasher::matches($stored), 'the hash survives a round trip through the database');
        $this->seeInDatabase('acc_sequences', ['entity_id' => $this->entity, 'last_number' => 1]);
    }

    public function testNumbersAreContiguousAndSurviveARolledBackPosting(): void
    {
        $this->posted('2026-03-02', 'One', [['1.1.02', 'D', 100], ['4.1.01', 'C', 100]]);

        try {
            // Taken inside the transaction, then rolled back by the closed period.
            $this->uc()->managePeriod()->close($this->entity, $this->periodId('2026-04-10'));
            $this->posted('2026-04-10', 'Refused', [['1.1.02', 'D', 100], ['4.1.01', 'C', 100]]);
            $this->fail('A closed period must refuse the posting.');
        } catch (ConflictException) {
            $this->addToAssertionCount(1);
        }

        $two = $this->posted('2026-03-03', 'Two', [['1.1.02', 'D', 100], ['4.1.01', 'C', 100]]);

        $this->assertSame(2, $two->number);
        $this->seeInDatabase('acc_sequences', ['last_number' => 2]);
        $this->assertSame(2, $this->db->table('acc_entries')->where('status !=', 'DRAFT')->countAllResults());
        $this->assertSame(0, $this->db->table('acc_entries')->where('status', 'DRAFT')->countAllResults(), 'the refused entry left no draft behind');
    }

    public function testInvalidEntryLeavesNoRowsBehind(): void
    {
        try {
            $this->posted('2026-03-02', 'Unbalanced', [['1.1.02', 'D', 100], ['4.1.01', 'C', 90]]);
            $this->fail('Unbalanced entries are rejected.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, $this->db->table('acc_entries')->countAllResults());
        $this->assertSame(0, $this->db->table('acc_entry_lines')->countAllResults());
        $this->assertSame(0, $this->db->table('acc_balances')->countAllResults());
    }

    public function testIdempotencyKeyDoesNotDuplicate(): void
    {
        $data = $this->data('2026-03-02', 'Sale', [['1.1.02', 'D', 100], ['4.1.01', 'C', 100]], null, 'erp:sale:9:1');

        $first  = $this->uc()->createEntry()->execute($this->entity, $data, true);
        $second = $this->uc()->createEntry()->execute($this->entity, $data, true);

        $this->assertTrue($first->created);
        $this->assertFalse($second->created);
        $this->assertSame($first->entry->id, $second->entry->id);
        $this->assertSame(1, $this->db->table('acc_entries')->countAllResults());
    }

    public function testDraftLifecycle(): void
    {
        $draft = $this->uc()->createEntry()->execute($this->entity, $this->data('2026-03-02', 'Draft', [['1.1.02', 'D', 100], ['4.1.01', 'C', 100]]))->entry;

        $revised = $this->uc()->manageDraft()->update($this->entity, (int) $draft->id, $this->data('2026-03-04', 'Revised', [['1.1.02', 'D', 250], ['4.1.01', 'C', 250]]));

        $this->assertSame('Revised', $revised->description);
        $this->assertSame(250, $revised->totalDebits());
        $this->assertSame(2, $this->db->table('acc_entry_lines')->countAllResults(), 'old lines were replaced, not accumulated');

        // Unchanged content still counts as a successful update (MySQL reports 0 affected rows).
        $this->uc()->manageDraft()->update($this->entity, (int) $draft->id, $this->data('2026-03-04', 'Revised', [['1.1.02', 'D', 250], ['4.1.01', 'C', 250]]));

        $this->uc()->postEntry()->execute($this->entity, (int) $draft->id);

        $this->expectException(ConflictException::class);
        $this->uc()->manageDraft()->delete($this->entity, (int) $draft->id);
    }

    public function testReversalAndSettlementRules(): void
    {
        $sale = $this->posted('2026-03-02', 'Credit sale', [['1.1.02', 'D', 1000], ['4.1.01', 'C', 1000]]);
        $pay  = $this->posted('2026-03-10', 'Collection', [['1.1.01', 'D', 1000], ['1.1.02', 'C', 1000]], '2026-03-10');

        $this->uc()->settleEntry()->execute($this->entity, (int) $pay->id, (int) $sale->id, 600);

        $this->assertCount(1, $this->uc()->entries()->settlementsOf($this->entity, (int) $sale->id));

        try {
            $this->uc()->reverseEntry()->execute($this->entity, (int) $sale->id, 'Cancel');
            $this->fail('A settled entry cannot be reversed.');
        } catch (ConflictException) {
            $this->addToAssertionCount(1);
        }

        $this->uc()->reverseEntry()->execute($this->entity, (int) $pay->id, 'Bounced');
        $this->assertSame([], $this->uc()->entries()->settlementsOf($this->entity, (int) $sale->id));

        $result = $this->uc()->reverseEntry()->execute($this->entity, (int) $sale->id, 'Cancel');
        $this->assertSame(EntryStatus::Reversed, $result->original->status);
        $this->seeInDatabase('acc_entries', ['id' => $sale->id, 'status' => 'REVERSED']);

        $this->expectException(ConflictException::class);
        $this->uc()->reverseEntry()->execute($this->entity, (int) $sale->id, 'Again');
    }

    public function testBatchRollsBackAsAWhole(): void
    {
        try {
            $this->uc()->postBatch()->execute($this->entity, [
                $this->data('2026-03-02', 'Good', [['1.1.02', 'D', 100], ['4.1.01', 'C', 100]]),
                $this->data('2026-03-03', 'Bad', [['1.1.02', 'D', 100], ['4.1.01', 'C', 1]]),
            ]);
            $this->fail('The batch must fail as a whole.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, $this->db->table('acc_entries')->countAllResults());
        $this->assertSame(0, $this->db->table('acc_sequences')->countAllResults(), 'the counter row was rolled back with the batch');
    }

    public function testAnOuterTransactionLetsAMigrationRunBeRolledBackAsADryRun(): void
    {
        $this->db->transBegin();

        $this->uc()->postBatch()->execute($this->entity, [
            $this->data('2026-03-02', 'A', [['1.1.02', 'D', 100], ['4.1.01', 'C', 100]], null, 'legacy:1'),
            $this->data('2026-03-03', 'B', [['1.1.02', 'D', 200], ['4.1.01', 'C', 200]], null, 'legacy:2'),
        ]);

        $this->assertSame(2, $this->db->table('acc_entries')->countAllResults(), 'visible inside the dry run');

        $this->db->transRollback();

        $this->assertSame(0, $this->db->table('acc_entries')->countAllResults());
        $this->assertSame(0, $this->db->table('acc_balances')->countAllResults());
        $this->assertSame(0, $this->db->table('acc_sequences')->countAllResults());
    }

    public function testBalanceCacheMatchesARebuildEvenWithBackdatedEntries(): void
    {
        $this->posted('2026-03-02', 'Mar', [['1.1.01', 'D', 500], ['3.1.01', 'C', 500]]);
        $this->posted('2026-04-02', 'Apr', [['1.1.01', 'D', 300], ['4.1.01', 'C', 300]]);
        $this->posted('2026-05-02', 'May', [['5.1.01', 'D', 120], ['1.1.01', 'C', 120]]);
        // Backdated into an earlier open period: the later periods must shift.
        $this->posted('2026-02-10', 'Feb', [['1.1.01', 'D', 40], ['3.1.01', 'C', 40]]);
        $this->posted('2026-04-20', 'Apr 2', [['1.1.01', 'D', 60], ['4.1.01', 'C', 60]]);

        $incremental = $this->balances();

        $rows = $this->uc()->recalculateBalances()->execute($this->entity);

        $this->assertSame($incremental, $this->balances(), 'recalculate-balances reproduces acc_balances');
        $this->assertSame(count($incremental), $rows);

        $cash = $this->ids['1.1.01'];
        $may  = $this->balances()[$cash . ':' . $this->periodId('2026-05-02')];
        $this->assertSame([900, 0, 120, 780], $may, 'opening carries 40 + 500 + 300 + 60, May pays 120');
    }

    public function testRebuildRepairsACorruptedCache(): void
    {
        $this->posted('2026-03-02', 'Mar', [['1.1.01', 'D', 500], ['3.1.01', 'C', 500]]);
        $good = $this->balances();

        $this->db->table('acc_balances')->update(['closing_balance' => 999999]);
        $this->assertNotSame($good, $this->balances());

        $this->uc()->recalculateBalances()->execute($this->entity);

        $this->assertSame($good, $this->balances());
    }

    public function testTrialBalanceFlagsCacheDivergence(): void
    {
        $this->posted('2026-03-02', 'Mar', [['1.1.01', 'D', 500], ['3.1.01', 'C', 500]]);

        $clean = $this->uc()->trialBalance()->execute($this->entity, new DateTimeImmutable('2026-03-01'), new DateTimeImmutable('2026-03-31'));
        $this->assertSame([], $clean->divergences);

        $this->db->table('acc_balances')->where('account_id', $this->ids['1.1.01'])->update(['closing_balance' => 1]);

        $broken = $this->uc()->trialBalance()->execute($this->entity, new DateTimeImmutable('2026-03-01'), new DateTimeImmutable('2026-03-31'));
        $this->assertSame([$this->ids['1.1.01']], $broken->divergences);

        $partial = $this->uc()->trialBalance()->execute($this->entity, new DateTimeImmutable('2026-03-01'), new DateTimeImmutable('2026-03-15'));
        $this->assertNull($partial->divergences, 'mid-period ranges are not checked against the cache');
    }

    public function testVerifyIntegrityDetectsAManualUpdate(): void
    {
        $first = $this->posted('2026-03-02', 'One', [['1.1.02', 'D', 100], ['4.1.01', 'C', 100]]);
        $this->posted('2026-03-03', 'Two', [['1.1.02', 'D', 200], ['4.1.01', 'C', 200]]);
        $this->posted('2026-03-04', 'Three', [['1.1.02', 'D', 300], ['4.1.01', 'C', 300]]);

        $this->assertTrue($this->uc()->integrity()->verify($this->entity)->isIntact());

        // A reversal changes only the status of the original: still intact.
        $this->uc()->reverseEntry()->execute($this->entity, (int) $first->id, 'Mistake');
        $this->assertTrue($this->uc()->integrity()->verify($this->entity)->isIntact());

        $this->db->table('acc_entries')->where('id', 2)->update(['description' => 'Quietly changed']);
        $report = $this->uc()->integrity()->verify($this->entity);

        $this->assertFalse($report->isIntact());
        $this->assertSame([2], array_unique(array_column($report->toArray()['problems'], 'entry_id')));
    }

    public function testVerifyIntegrityDetectsATamperedLineAndADeletedEntry(): void
    {
        $this->posted('2026-03-02', 'One', [['1.1.02', 'D', 100], ['4.1.01', 'C', 100]]);
        $this->posted('2026-03-03', 'Two', [['1.1.02', 'D', 200], ['4.1.01', 'C', 200]]);
        $this->posted('2026-03-04', 'Three', [['1.1.02', 'D', 300], ['4.1.01', 'C', 300]]);

        $this->db->table('acc_entry_lines')->where('entry_id', 3)->where('side', 'D')->update(['amount' => 301]);
        $problems = $this->uc()->integrity()->verify($this->entity)->toArray()['problems'];

        $this->assertContains(3, array_column($problems, 'entry_id'));

        $this->db->table('acc_entry_lines')->where('entry_id', 3)->where('side', 'D')->update(['amount' => 300]);
        $this->db->table('acc_entry_lines')->where('entry_id', 2)->delete();
        $this->db->table('acc_entries')->where('id', 2)->delete();

        $this->assertFalse($this->uc()->integrity()->verify($this->entity)->isIntact());
    }

    public function testPostedStatusCannotBeRewrittenThroughTheRepository(): void
    {
        $entry = $this->posted('2026-03-02', 'One', [['1.1.02', 'D', 100], ['4.1.01', 'C', 100]]);

        $this->expectException(ConflictException::class);
        $this->uc()->manageDraft()->update($this->entity, (int) $entry->id, $this->data('2026-03-02', 'Edit', [['1.1.02', 'D', 100], ['4.1.01', 'C', 100]]));
    }

    public function testListingHonoursFiltersAndPaging(): void
    {
        foreach ([2, 3, 4] as $day) {
            $this->posted('2026-03-0' . $day, 'Sale ' . $day, [['1.1.02', 'D', 10], ['4.1.01', 'C', 10]]);
        }

        $page = $this->uc()->entries()->list($this->entity, new EntryFilter(), null, 2);
        $next = $this->uc()->entries()->list($this->entity, new EntryFilter(), (int) $page[1]->id, 2);

        $this->assertSame([3, 2], array_map(static fn ($e): ?int => $e->number, $page));
        $this->assertSame([1], array_map(static fn ($e): ?int => $e->number, $next));

        $byAccount = $this->uc()->entries()->list($this->entity, new EntryFilter(null, null, null, null, null, $this->ids['4.1.01']), null, 10);
        $this->assertCount(3, $byAccount);
    }
}
