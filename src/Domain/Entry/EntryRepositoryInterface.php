<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Domain\Entry;

use DateTimeImmutable;

/**
 * Storage of journal entries. Every lookup is scoped to an entity. The write
 * methods that post or reverse must be called inside a transaction.
 */
interface EntryRepositoryInterface
{
    public function find(int $entityId, int $id): ?JournalEntry;

    public function findByIdempotencyKey(int $entityId, string $key): ?JournalEntry;

    /**
     * The entry that reverses the given one, if any.
     */
    public function reversalOf(int $entityId, int $entryId): ?JournalEntry;

    /**
     * @return list<JournalEntry> Newest first, with their lines.
     */
    public function search(int $entityId, EntryFilter $filter, ?int $beforeId, int $limit): array;

    /**
     * Stores a new draft with its lines.
     */
    public function insert(JournalEntry $draft): JournalEntry;

    /**
     * Replaces the header and lines of a stored draft. Refuses anything that is not a draft.
     */
    public function replaceDraft(JournalEntry $draft): JournalEntry;

    public function deleteDraft(int $entityId, int $id): void;

    public function countDrafts(int $entityId, DateTimeImmutable $from, DateTimeImmutable $to): int;

    /**
     * Takes the entity-wide posting lock, held until the transaction ends, so
     * numbering and the hash chain are serialized per entity.
     */
    public function lockEntity(int $entityId): void;

    /**
     * Next number of the entity and year, with no gaps: the counter moves back
     * if the transaction rolls back. Call after {@see lockEntity()}.
     */
    public function nextNumber(int $entityId, int $fiscalYearId): int;

    /**
     * Position and previous hash for the next entry of the entity's hash chain.
     * Call after {@see lockEntity()}.
     *
     * @return array{0: int, 1: ?string}
     */
    public function nextChainLink(int $entityId): array;

    /**
     * Turns a stored draft into the given posted entry.
     */
    public function savePosted(JournalEntry $posted): JournalEntry;

    public function markReversed(int $entityId, int $id): void;

    /**
     * Records a settlement link.
     */
    public function insertSettlement(Settlement $settlement): Settlement;

    /**
     * Settlements that count: those whose settlement entry is still in force
     * (not reversed).
     *
     * @return list<Settlement> Ordered by date, then id.
     */
    public function activeSettlementsOf(int $settledEntryId): array;

    /**
     * Sum of the active settlements made by one settlement entry.
     */
    public function amountSettledBy(int $settlementEntryId): int;

    /**
     * Posted and reversed entries in hash chain order.
     *
     * @return iterable<JournalEntry>
     */
    public function chain(int $entityId): iterable;
}
