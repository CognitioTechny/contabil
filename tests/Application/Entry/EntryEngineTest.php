<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Tests\Application\Entry;

use CodeIgniter\Test\CIUnitTestCase;
use CognitioTechny\Contabil\Application\Entry\EntryData;
use CognitioTechny\Contabil\Domain\Calendar\FiscalYear;
use CognitioTechny\Contabil\Domain\Entry\EntryFilter;
use CognitioTechny\Contabil\Domain\Entry\EntryStatus;
use CognitioTechny\Contabil\Domain\Exception\ConflictException;
use CognitioTechny\Contabil\Domain\Exception\NotFoundException;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LedgerKit;
use Throwable;

/**
 * Double entry, numbering, reversal, idempotency and batches, with no database.
 *
 * @internal
 */
final class EntryEngineTest extends CIUnitTestCase
{
    private LedgerKit $kit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kit = (new LedgerKit())->standard();
    }

    /**
     * @return list<array{string, string, int}>
     */
    private function sale(int $amount = 1000): array
    {
        return [['1.1.02', 'D', $amount], ['4.1.01', 'C', $amount]];
    }

    private function lastNumber(): int
    {
        $max = 0;

        foreach ($this->kit->ledger->chain(LedgerKit::ENTITY) as $entry) {
            $max = max($max, (int) $entry->number);
        }

        return $max;
    }

    public function testPostingAssignsGapFreeNumbers(): void
    {
        $one = $this->kit->posted('2026-03-02', 'Sale 1', $this->sale());
        $two = $this->kit->posted('2026-03-03', 'Sale 2', $this->sale());

        $this->assertSame([1, 2], [$one->number, $two->number]);
        $this->assertSame(EntryStatus::Posted, $two->status);
        $this->assertSame('u1', $two->userId);
        $this->assertNotNull($two->hash);
        $this->assertSame($one->hash, $two->previousHash);
    }

    public function testNumberingIsPerFiscalYear(): void
    {
        $this->kit->years->insert(...FiscalYear::start(LedgerKit::ENTITY, new DateTimeImmutable('2027-01-01')));

        $a = $this->kit->posted('2026-12-30', 'Late 2026', $this->sale());
        $b = $this->kit->posted('2027-01-02', 'Early 2027', $this->sale());

        $this->assertSame([1, 1], [$a->number, $b->number]);
        $this->assertSame(2, $b->chainPosition, 'the hash chain still runs across years');
    }

    public function testBalanceCacheIsUpdatedWithThePosting(): void
    {
        $this->kit->posted('2026-03-02', 'Sale', $this->sale(1500));

        $this->assertCount(2, $this->kit->ledger->applied);
        $this->assertSame([$this->kit->ids['1.1.02'], 1500, 0], [$this->kit->ledger->applied[0][0], $this->kit->ledger->applied[0][2], $this->kit->ledger->applied[0][3]]);
    }

    public function testDraftsAreEditableAndOutsideTheLedger(): void
    {
        $result = $this->kit->create->execute(LedgerKit::ENTITY, $this->kit->entry('2026-03-02', 'Draft sale', $this->sale()));

        $this->assertTrue($result->created);
        $this->assertSame(EntryStatus::Draft, $result->entry->status);
        $this->assertNull($result->entry->number);
        $this->assertSame([], $this->kit->ledger->applied);

        $revised = $this->kit->drafts->update(LedgerKit::ENTITY, (int) $result->entry->id, $this->kit->entry('2026-03-03', 'Revised', $this->sale(2000)));

        $this->assertSame('Revised', $revised->description);
        $this->assertSame(2000, $revised->totalDebits());

        $posted = $this->kit->post->execute(LedgerKit::ENTITY, (int) $result->entry->id);
        $this->assertSame(1, $posted->number);
    }

    public function testDraftCanBeDeleted(): void
    {
        $draft = $this->kit->create->execute(LedgerKit::ENTITY, $this->kit->entry('2026-03-02', 'Mistake', $this->sale()))->entry;

        $this->kit->drafts->delete(LedgerKit::ENTITY, (int) $draft->id);

        $this->expectException(NotFoundException::class);
        $this->kit->queries->get(LedgerKit::ENTITY, (int) $draft->id);
    }

    public function testPostedEntryCannotBeChangedOrDeletedByAnyPath(): void
    {
        $posted = $this->kit->posted('2026-03-02', 'Sale', $this->sale());

        foreach ([
            fn () => $this->kit->drafts->update(LedgerKit::ENTITY, (int) $posted->id, $this->kit->entry('2026-03-02', 'Edit', $this->sale())),
            fn () => $this->kit->drafts->delete(LedgerKit::ENTITY, (int) $posted->id),
            fn () => $this->kit->post->execute(LedgerKit::ENTITY, (int) $posted->id),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A posted entry was changed.');
            } catch (ConflictException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame($posted->hash, $this->kit->queries->get(LedgerKit::ENTITY, (int) $posted->id)->hash);
    }

    /**
     * @param list<array{string, string, int}> $lines
     * @param class-string<Throwable>          $exception
     */
    #[DataProvider('provideInvalidEntriesAreRejectedWithoutSideEffects')]
    public function testInvalidEntriesAreRejectedWithoutSideEffects(string $date, array $lines, string $exception): void
    {
        $before = $this->kit->ledger->chain(LedgerKit::ENTITY);

        try {
            $this->kit->create->execute(LedgerKit::ENTITY, $this->kit->entry($date, 'Bad', $lines), true);
            $this->fail('The entry should have been rejected.');
        } catch (Throwable $e) {
            $this->assertInstanceOf($exception, $e);
        }

        $this->assertSame($before, $this->kit->ledger->chain(LedgerKit::ENTITY));
        $this->assertSame([], $this->kit->ledger->applied);
        $this->assertSame([], $this->kit->queries->list(LedgerKit::ENTITY, new EntryFilter()), 'not even a draft is left behind');
        $this->assertSame([], $this->kit->dispatcher->events);
    }

    /**
     * @return array<string, array{0: string, 1: list<array{string, string, int}>, 2: class-string<Throwable>}>
     */
    public static function provideInvalidEntriesAreRejectedWithoutSideEffects(): iterable
    {
        return [
            'unbalanced'     => ['2026-03-02', [['1.1.02', 'D', 100], ['4.1.01', 'C', 90]], ValidationException::class],
            'one line'       => ['2026-03-02', [['1.1.02', 'D', 100]], ValidationException::class],
            'group account'  => ['2026-03-02', [['1', 'D', 100], ['4.1.01', 'C', 100]], ValidationException::class],
            'no such period' => ['2030-01-02', [['1.1.02', 'D', 100], ['4.1.01', 'C', 100]], ValidationException::class],
        ];
    }

    public function testInactiveAndForeignAccountsAreRejected(): void
    {
        $this->kit->chart->deactivate->execute(LedgerKit::ENTITY, $this->kit->ids['5.1.02']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('inactive');
        $this->kit->posted('2026-03-02', 'Supplies', [['5.1.02', 'D', 100], ['1.1.01', 'C', 100]]);
    }

    public function testAccountOfAnotherEntityIsRejected(): void
    {
        $other   = $this->kit->chart->store->create('Other', null, 'USD', 'ACCRUAL');
        $foreign = $this->kit->chart->create->execute($other, '1.1', 'Foreign cash', 'ASSET');

        $data = new EntryData('2026-03-02', 'Mixed', [
            ['account_id' => $foreign->id, 'side' => 'D', 'amount' => 100],
            ['account_id' => $this->kit->ids['4.1.01'], 'side' => 'C', 'amount' => 100],
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('does not exist in this entity');
        $this->kit->create->execute(LedgerKit::ENTITY, $data, true);
    }

    public function testNonIntegerAmountsAreRejected(): void
    {
        $data = new EntryData('2026-03-02', 'Float', [
            ['account_id' => $this->kit->ids['1.1.02'], 'side' => 'D', 'amount' => 10.5],
            ['account_id' => $this->kit->ids['4.1.01'], 'side' => 'C', 'amount' => 10.5],
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('integer number of minor units');
        $this->kit->create->execute(LedgerKit::ENTITY, $data);
    }

    public function testFailedPostingLeavesNoGapInTheNumbers(): void
    {
        $this->kit->posted('2026-03-02', 'One', $this->sale());

        try {
            $this->kit->posted('2026-03-03', 'Broken', [['1.1.02', 'D', 5], ['4.1.01', 'C', 4]]);
        } catch (ValidationException) {
        }

        $three = $this->kit->posted('2026-03-04', 'Three', $this->sale());

        $this->assertSame(2, $three->number);
        $this->assertSame(2, $this->lastNumber());
    }

    public function testClosedPeriodRejectsEntries(): void
    {
        $period = $this->kit->years->periodContaining(LedgerKit::ENTITY, new DateTimeImmutable('2026-03-10'))[1];
        $this->kit->periods->close(LedgerKit::ENTITY, (int) $period->id);

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('CLOSED');
        $this->kit->posted('2026-03-10', 'Late', $this->sale());
    }

    public function testReversalCancelsTheOriginalExactly(): void
    {
        $original = $this->kit->posted('2026-03-02', 'Sale', $this->sale(1234));

        $result = $this->kit->reverse->execute(LedgerKit::ENTITY, (int) $original->id, 'Wrong customer');

        $this->assertSame(EntryStatus::Reversed, $result->original->status);
        $this->assertSame(EntryStatus::Posted, $result->reversal->status);
        $this->assertSame($original->id, $result->reversal->reversalOfId);
        $this->assertSame(2, $result->reversal->number);
        $this->assertSame(EntryStatus::Reversed, $this->kit->queries->get(LedgerKit::ENTITY, (int) $original->id)->status);

        $sums = $this->kit->ledger->accrualSums(LedgerKit::ENTITY, null, null);

        foreach ($sums as [$debits, $credits]) {
            $this->assertSame($debits, $credits, 'every account nets to zero');
        }
    }

    public function testSecondReversalIsRejected(): void
    {
        $original = $this->kit->posted('2026-03-02', 'Sale', $this->sale());
        $this->kit->reverse->execute(LedgerKit::ENTITY, (int) $original->id, 'Mistake');

        $this->expectException(ConflictException::class);
        $this->kit->reverse->execute(LedgerKit::ENTITY, (int) $original->id, 'Mistake again');
    }

    public function testReversalCannotBeReversedButCanBeCorrected(): void
    {
        $original = $this->kit->posted('2026-03-02', 'Sale', $this->sale());
        $reversal = $this->kit->reverse->execute(LedgerKit::ENTITY, (int) $original->id, 'Mistake')->reversal;

        $this->expectException(ConflictException::class);
        $this->kit->reverse->execute(LedgerKit::ENTITY, (int) $reversal->id, 'Undo undo');
    }

    public function testReversalInAClosedPeriodNeedsAnotherDate(): void
    {
        $original = $this->kit->posted('2026-03-02', 'Sale', $this->sale());
        $period   = $this->kit->years->periodContaining(LedgerKit::ENTITY, new DateTimeImmutable('2026-03-02'))[1];
        $this->kit->periods->close(LedgerKit::ENTITY, (int) $period->id);

        try {
            $this->kit->reverse->execute(LedgerKit::ENTITY, (int) $original->id, 'Mistake');
            $this->fail('The closed period should refuse the reversal.');
        } catch (ConflictException) {
            $this->assertSame(EntryStatus::Posted, $this->kit->queries->get(LedgerKit::ENTITY, (int) $original->id)->status, 'nothing changed');
        }

        $result = $this->kit->reverse->execute(LedgerKit::ENTITY, (int) $original->id, 'Mistake', '2026-04-02');
        $this->assertSame('2026-04-02', $result->reversal->accrualDate->format('Y-m-d'));
    }

    public function testReversalNeedsAReason(): void
    {
        $original = $this->kit->posted('2026-03-02', 'Sale', $this->sale());

        $this->expectException(ValidationException::class);
        $this->kit->reverse->execute(LedgerKit::ENTITY, (int) $original->id, '');
    }

    public function testCorrectionIsReversalPlusNewEntry(): void
    {
        $wrong = $this->kit->posted('2026-03-02', 'Sale 100', $this->sale(100), null, null, 'invoice', '77');
        $this->kit->reverse->execute(LedgerKit::ENTITY, (int) $wrong->id, 'Wrong amount');
        $this->kit->posted('2026-03-02', 'Sale 150', $this->sale(150), null, null, 'invoice', '77');

        $listing = $this->kit->listing->bySource(LedgerKit::ENTITY, 'invoice', '77');

        $this->assertCount(3, $listing->entries, 'original, reversal and replacement share the source');
        $this->assertSame([[150, 0]], array_values(array_map(
            static fn (array $sum): array => [$sum[0] - $sum[1], 0],
            [$this->kit->ledger->accrualSums(LedgerKit::ENTITY, null, null)[$this->kit->ids['1.1.02']]],
        )));
    }

    public function testIdempotencyKeyReturnsTheExistingEntry(): void
    {
        $first  = $this->kit->create->execute(LedgerKit::ENTITY, $this->kit->entry('2026-03-02', 'Sale', $this->sale(), null, 'erp:sale:1:1'), true);
        $second = $this->kit->create->execute(LedgerKit::ENTITY, $this->kit->entry('2026-03-02', 'Sale', $this->sale(), null, 'erp:sale:1:1'), true);

        $this->assertTrue($first->created);
        $this->assertFalse($second->created);
        $this->assertSame($first->entry->id, $second->entry->id);
        $this->assertCount(1, $this->kit->queries->list(LedgerKit::ENTITY, new EntryFilter()), 'nothing was duplicated');
        $this->assertSame(['EntryPosted'], $this->kit->dispatcher->names(), 'the retry published nothing');
    }

    public function testReusedKeyWithDifferentContentConflicts(): void
    {
        $this->kit->create->execute(LedgerKit::ENTITY, $this->kit->entry('2026-03-02', 'Sale', $this->sale(100), null, 'k1'), true);

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('different content');
        $this->kit->create->execute(LedgerKit::ENTITY, $this->kit->entry('2026-03-02', 'Sale', $this->sale(999), null, 'k1'), true);
    }

    public function testRetryOfADraftWithPostPostsIt(): void
    {
        $draft = $this->kit->create->execute(LedgerKit::ENTITY, $this->kit->entry('2026-03-02', 'Sale', $this->sale(), null, 'k2'));
        $final = $this->kit->create->execute(LedgerKit::ENTITY, $this->kit->entry('2026-03-02', 'Sale', $this->sale(), null, 'k2'), true);

        $this->assertSame($draft->entry->id, $final->entry->id);
        $this->assertSame(EntryStatus::Posted, $final->entry->status);
    }

    public function testBatchIsAtomic(): void
    {
        $good = $this->kit->entry('2026-03-02', 'Good', $this->sale());
        $bad  = $this->kit->entry('2026-03-03', 'Bad', [['1.1.02', 'D', 5], ['4.1.01', 'C', 4]]);

        try {
            $this->kit->batch->execute(LedgerKit::ENTITY, [$good, $bad]);
            $this->fail('The batch should fail as a whole.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Entry 2 of the batch', $e->getMessage());
        }

        $this->assertSame([], $this->kit->queries->list(LedgerKit::ENTITY, new EntryFilter()));
        $this->assertSame([], $this->kit->dispatcher->events);

        $results = $this->kit->batch->execute(LedgerKit::ENTITY, [$good, $this->kit->entry('2026-03-03', 'Second', $this->sale())]);

        $this->assertSame([1, 2], array_map(static fn ($r): ?int => $r->entry->number, $results));
    }

    public function testBatchRetryDoesNotDuplicate(): void
    {
        $batch = [
            $this->kit->entry('2026-03-02', 'A', $this->sale(), null, 'b-1'),
            $this->kit->entry('2026-03-03', 'B', $this->sale(), null, 'b-2'),
        ];

        $this->kit->batch->execute(LedgerKit::ENTITY, $batch);
        $again = $this->kit->batch->execute(LedgerKit::ENTITY, $batch);

        $this->assertSame([false, false], array_map(static fn ($r): bool => $r->created, $again));
        $this->assertCount(2, $this->kit->queries->list(LedgerKit::ENTITY, new EntryFilter()));
    }

    public function testEmptyBatchIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->kit->batch->execute(LedgerKit::ENTITY, []);
    }

    public function testListFilters(): void
    {
        $this->kit->posted('2026-03-02', 'A', $this->sale(), null, null, 'invoice', '1');
        $this->kit->create->execute(LedgerKit::ENTITY, $this->kit->entry('2026-03-03', 'Draft', $this->sale()));

        $drafts = $this->kit->queries->list(LedgerKit::ENTITY, new EntryFilter(EntryStatus::Draft));
        $source = $this->kit->queries->list(LedgerKit::ENTITY, new EntryFilter(null, null, null, 'invoice', '1'));

        $this->assertCount(1, $drafts);
        $this->assertCount(1, $source);
        $this->assertCount(2, $this->kit->queries->list(LedgerKit::ENTITY, new EntryFilter()));
    }

    public function testEventsAreDispatchedAfterTheCommitAndListenerFailuresAreContained(): void
    {
        $this->kit->dispatcher->failing = true;

        $entry = $this->kit->posted('2026-03-02', 'Sale', $this->sale());

        $this->assertSame(EntryStatus::Posted, $this->kit->queries->get(LedgerKit::ENTITY, (int) $entry->id)->status);
        $this->assertSame(['EntryPosted'], $this->kit->dispatcher->names());
    }

    public function testReversalPublishesBothFacts(): void
    {
        $original = $this->kit->posted('2026-03-02', 'Sale', $this->sale());
        $this->kit->reverse->execute(LedgerKit::ENTITY, (int) $original->id, 'Mistake');

        $this->assertSame(['EntryPosted', 'EntryPosted', 'EntryReversed'], $this->kit->dispatcher->names(), 'the reversal enters the hash chain too');
    }
}
