<?php

declare(strict_types=1);

namespace Tests\Support;

use CognitioTechny\Contabil\Application\Reports\LedgerLine;
use CognitioTechny\Contabil\Application\Reports\ReportReaderInterface;
use CognitioTechny\Contabil\Application\Repository\TransactionRunnerInterface;
use CognitioTechny\Contabil\Domain\Balance\BalanceRepositoryInterface;
use CognitioTechny\Contabil\Domain\Chart\AccountType;
use CognitioTechny\Contabil\Domain\Entry\EntryFilter;
use CognitioTechny\Contabil\Domain\Entry\EntryRepositoryInterface;
use CognitioTechny\Contabil\Domain\Entry\EntryStatus;
use CognitioTechny\Contabil\Domain\Entry\JournalEntry;
use CognitioTechny\Contabil\Domain\Entry\Line;
use CognitioTechny\Contabil\Domain\Entry\Settlement;
use CognitioTechny\Contabil\Domain\Entry\Side;
use CognitioTechny\Contabil\Domain\Exception\ConflictException;
use DateTimeImmutable;
use Throwable;

/**
 * Database-free stand-in for the entry storage, the balance cache, the report
 * queries and the transaction runner. A failed transaction restores the ledger,
 * counters included, so numbering stays gap-free exactly as in the database.
 */
final class InMemoryLedger implements EntryRepositoryInterface, BalanceRepositoryInterface, ReportReaderInterface, TransactionRunnerInterface
{
    /**
     * @var array<int, JournalEntry>
     */
    private array $entries = [];

    /**
     * @var list<Settlement>
     */
    private array $settlements = [];

    /**
     * @var array<string, int>
     */
    private array $sequences = [];

    private int $nextId           = 1;
    private int $nextSettlementId = 1;

    /**
     * @var list<array{int, int, int, int}> Account, period, debits, credits.
     */
    public array $applied = [];

    public function __construct(private readonly InMemoryChart $chart)
    {
    }

    // --- EntryRepositoryInterface -------------------------------------

    public function find(int $entityId, int $id): ?JournalEntry
    {
        $entry = $this->entries[$id] ?? null;

        return $entry !== null && $entry->entityId === $entityId ? $entry : null;
    }

    public function findByIdempotencyKey(int $entityId, string $key): ?JournalEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->entityId === $entityId && $entry->idempotencyKey === $key) {
                return $entry;
            }
        }

        return null;
    }

    public function reversalOf(int $entityId, int $entryId): ?JournalEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->entityId === $entityId && $entry->reversalOfId === $entryId) {
                return $entry;
            }
        }

        return null;
    }

    public function search(int $entityId, EntryFilter $filter, ?int $beforeId, int $limit): array
    {
        $found = array_filter($this->entries, static fn (JournalEntry $e): bool => $e->entityId === $entityId
            && ($filter->status === null || $e->status === $filter->status)
            && ($filter->from === null || $e->accrualDate >= $filter->from)
            && ($filter->to === null || $e->accrualDate <= $filter->to)
            && ($filter->sourceType === null || $e->sourceType === $filter->sourceType)
            && ($filter->sourceId === null || $e->sourceId === $filter->sourceId)
            && ($beforeId === null || $e->id < $beforeId));

        krsort($found);

        return array_slice(array_values($found), 0, $limit);
    }

    public function insert(JournalEntry $draft): JournalEntry
    {
        $id    = $this->nextId++;
        $lines = array_map(static fn (Line $l): Line => new Line($l->accountId, $l->side, $l->amount, $l->description, $l->position, $id * 1000 + $l->position), $draft->lines);

        return $this->entries[$id] = $draft->withId($id)->withLines($lines);
    }

    public function replaceDraft(JournalEntry $draft): JournalEntry
    {
        if (($this->entries[$draft->id]->status ?? null) !== EntryStatus::Draft) {
            throw new ConflictException('Only a draft can be changed.');
        }

        return $this->entries[$draft->id] = $draft;
    }

    public function deleteDraft(int $entityId, int $id): void
    {
        if (($this->entries[$id]->status ?? null) !== EntryStatus::Draft) {
            throw new ConflictException('Only a draft can be deleted.');
        }

        unset($this->entries[$id]);
    }

    public function countDrafts(int $entityId, DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return count(array_filter($this->entries, static fn (JournalEntry $e): bool => $e->entityId === $entityId
            && $e->status === EntryStatus::Draft && $e->accrualDate >= $from && $e->accrualDate <= $to));
    }

    public function lockEntity(int $entityId): void
    {
    }

    public function nextNumber(int $entityId, int $fiscalYearId): int
    {
        $key = $entityId . ':' . $fiscalYearId;

        return $this->sequences[$key] = ($this->sequences[$key] ?? 0) + 1;
    }

    public function nextChainLink(int $entityId): array
    {
        $last = null;

        foreach ($this->entries as $entry) {
            if ($entry->entityId === $entityId && $entry->chainPosition !== null && ($last === null || $entry->chainPosition > $last->chainPosition)) {
                $last = $entry;
            }
        }

        return $last === null ? [1, null] : [$last->chainPosition + 1, $last->hash];
    }

    public function savePosted(JournalEntry $posted): JournalEntry
    {
        if (($this->entries[$posted->id]->status ?? null) !== EntryStatus::Draft) {
            throw new ConflictException('Only a draft can be posted.');
        }

        return $this->entries[$posted->id] = $posted;
    }

    public function markReversed(int $entityId, int $id): void
    {
        $this->entries[$id] = $this->entries[$id]->markReversed();
    }

    public function insertSettlement(Settlement $settlement): Settlement
    {
        return $this->settlements[] = $settlement->withId($this->nextSettlementId++);
    }

    public function activeSettlementsOf(int $settledEntryId): array
    {
        $found = array_values(array_filter($this->settlements, fn (Settlement $s): bool => $s->settledEntryId === $settledEntryId
            && $this->entries[$s->settlementEntryId]->status !== EntryStatus::Reversed));

        usort($found, static fn (Settlement $a, Settlement $b): int => [$a->date, $a->id] <=> [$b->date, $b->id]);

        return $found;
    }

    public function amountSettledBy(int $settlementEntryId): int
    {
        return array_sum(array_map(
            static fn (Settlement $s): int => $s->settlementEntryId === $settlementEntryId ? $s->amount : 0,
            $this->settlements,
        ));
    }

    public function chain(int $entityId): iterable
    {
        $chain = array_filter($this->entries, static fn (JournalEntry $e): bool => $e->entityId === $entityId && $e->chainPosition !== null);

        usort($chain, static fn (JournalEntry $a, JournalEntry $b): int => $a->chainPosition <=> $b->chainPosition);

        return $chain;
    }

    /**
     * Test hook: replaces a stored entry as a manual UPDATE in the database would.
     */
    public function tamper(JournalEntry $entry): void
    {
        $this->entries[$entry->id] = $entry;
    }

    // --- BalanceRepositoryInterface -----------------------------------

    public function apply(int $accountId, int $periodId, int $debits, int $credits): void
    {
        $this->applied[] = [$accountId, $periodId, $debits, $credits];
    }

    public function rebuild(int $entityId): int
    {
        return 0;
    }

    public function closingAsOf(int $entityId, DateTimeImmutable $date): ?array
    {
        return null;
    }

    // --- ReportReaderInterface ----------------------------------------

    public function accrualSums(int $entityId, ?DateTimeImmutable $from, ?DateTimeImmutable $to): array
    {
        $sums = [];

        foreach ($this->ledgerRows($entityId, $from, $to) as $line) {
            $sums[$line->accountId] ??= [0, 0];
            $sums[$line->accountId][$line->side === Side::Debit ? 0 : 1] += $line->amount;
        }

        return $sums;
    }

    public function ledgerLines(int $entityId, array $accountIds, ?DateTimeImmutable $from, ?DateTimeImmutable $to, ?array $after, int $limit): array
    {
        $rows = array_filter(
            $this->ledgerRows($entityId, $from, $to),
            static fn (LedgerLine $l): bool => in_array($l->accountId, $accountIds, true) && ($after === null || $l->cursor() > $after),
        );

        return array_slice(array_values($rows), 0, $limit);
    }

    public function sumsFor(int $entityId, array $accountIds, ?DateTimeImmutable $from, ?DateTimeImmutable $to, ?array $upTo = null): array
    {
        $sums = [0, 0];

        foreach ($this->ledgerRows($entityId, $from, $to) as $line) {
            if (in_array($line->accountId, $accountIds, true) && ($upTo === null || $line->cursor() <= $upTo)) {
                $sums[$line->side === Side::Debit ? 0 : 1] += $line->amount;
            }
        }

        return $sums;
    }

    public function journal(int $entityId, ?DateTimeImmutable $from, ?DateTimeImmutable $to, ?array $after, int $limit): array
    {
        $entries = array_filter($this->entries, static fn (JournalEntry $e): bool => $e->entityId === $entityId
            && $e->status->isInLedger()
            && ($from === null || $e->accrualDate >= $from)
            && ($to === null || $e->accrualDate <= $to)
            && ($after === null || [$e->accrualDate->format('Y-m-d'), $e->number] > $after));

        usort($entries, static fn (JournalEntry $a, JournalEntry $b): int => [$a->accrualDate, $a->number] <=> [$b->accrualDate, $b->number]);

        return array_slice($entries, 0, $limit);
    }

    public function bySource(int $entityId, string $sourceType, string $sourceId): array
    {
        $found = array_filter($this->entries, static fn (JournalEntry $e): bool => $e->entityId === $entityId
            && $e->status->isInLedger() && $e->sourceType === $sourceType && $e->sourceId === $sourceId);

        usort($found, static fn (JournalEntry $a, JournalEntry $b): int => $a->chainPosition <=> $b->chainPosition);

        return $found;
    }

    public function directCashSums(int $entityId, ?DateTimeImmutable $from, ?DateTimeImmutable $to): array
    {
        $sums = [];

        foreach ($this->entries as $entry) {
            if ($entry->entityId !== $entityId || ! $entry->status->isInLedger() || $entry->cashDate === null
                                               || ($from !== null && $entry->cashDate < $from) || ($to !== null && $entry->cashDate > $to)) {
                continue;
            }

            foreach ($entry->lines as $line) {
                if ($this->isResultAccount($entityId, $line->accountId)) {
                    $sums[$line->accountId] ??= [0, 0];
                    $sums[$line->accountId][$line->side === Side::Debit ? 0 : 1] += $line->amount;
                }
            }
        }

        return $sums;
    }

    public function settlementsUpTo(int $entityId, ?DateTimeImmutable $to): array
    {
        $found = array_values(array_filter($this->settlements, fn (Settlement $s): bool => ($this->entries[$s->settledEntryId]->entityId === $entityId)
            && $this->entries[$s->settlementEntryId]->status !== EntryStatus::Reversed
            && ($to === null || $s->date <= $to)));

        usort($found, static fn (Settlement $a, Settlement $b): int => [$a->date, $a->id] <=> [$b->date, $b->id]);

        return $found;
    }

    public function settledProfiles(int $entityId, array $entryIds): array
    {
        $profiles = [];

        foreach ($entryIds as $id) {
            $entry = $this->find($entityId, $id);

            if ($entry === null) {
                continue;
            }

            $profiles[$id] = ['total' => $entry->totalDebits(), 'lines' => []];

            foreach ($entry->lines as $line) {
                if ($this->isResultAccount($entityId, $line->accountId)) {
                    $profiles[$id]['lines'][] = [$line->accountId, $line->side, $line->amount];
                }
            }
        }

        return $profiles;
    }

    // --- TransactionRunnerInterface -----------------------------------

    public function run(callable $work): mixed
    {
        $snapshot = [$this->entries, $this->settlements, $this->sequences, $this->nextId, $this->nextSettlementId, $this->applied];

        try {
            return $work();
        } catch (Throwable $e) {
            [$this->entries, $this->settlements, $this->sequences, $this->nextId, $this->nextSettlementId, $this->applied] = $snapshot;

            throw $e;
        }
    }

    /**
     * @return list<LedgerLine> In ledger order.
     */
    private function ledgerRows(int $entityId, ?DateTimeImmutable $from, ?DateTimeImmutable $to): array
    {
        $rows = [];

        foreach ($this->entries as $entry) {
            if ($entry->entityId !== $entityId || ! $entry->status->isInLedger()
                                               || ($from !== null && $entry->accrualDate < $from) || ($to !== null && $entry->accrualDate > $to)) {
                continue;
            }

            foreach ($entry->lines as $line) {
                $rows[] = new LedgerLine(
                    (int) $line->id,
                    (int) $entry->id,
                    (int) $entry->number,
                    $entry->accrualDate,
                    $entry->description,
                    $line->description,
                    $line->accountId,
                    $line->side,
                    $line->amount,
                    $entry->sourceType,
                    $entry->sourceId,
                );
            }
        }

        usort($rows, static fn (LedgerLine $a, LedgerLine $b): int => $a->cursor() <=> $b->cursor());

        return $rows;
    }

    private function isResultAccount(int $entityId, int $accountId): bool
    {
        $type = $this->chart->find($entityId, $accountId)?->type;

        return $type === AccountType::Revenue || $type === AccountType::Expense;
    }
}
