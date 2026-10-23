<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Entry;

use CognitioTechny\Contabil\Application\Repository\EntityGuard;
use CognitioTechny\Contabil\Domain\Entry\EntryFilter;
use CognitioTechny\Contabil\Domain\Entry\EntryRepositoryInterface;
use CognitioTechny\Contabil\Domain\Entry\JournalEntry;
use CognitioTechny\Contabil\Domain\Entry\Settlement;
use CognitioTechny\Contabil\Domain\Exception\NotFoundException;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;

/**
 * Read side of the entries: one entry with its settlements, or a page of a list.
 */
final class EntryQueries
{
    public const MAX_PAGE = 200;

    public function __construct(
        private readonly EntityGuard $entities,
        private readonly EntryRepositoryInterface $entries,
    ) {
    }

    /**
     * @throws NotFoundException
     */
    public function get(int $entityId, int $entryId): JournalEntry
    {
        return $this->entries->find($entityId, $entryId)
            ?? throw new NotFoundException(sprintf('Entry %d does not exist in this entity.', $entryId));
    }

    /**
     * @return list<Settlement> The active settlements paying this entry.
     */
    public function settlementsOf(int $entityId, int $entryId): array
    {
        $this->get($entityId, $entryId);

        return $this->entries->activeSettlementsOf($entryId);
    }

    /**
     * Newest first; pass the id of the last entry of a page as `$beforeId` to get the next one.
     *
     * @return list<JournalEntry>
     */
    public function list(int $entityId, EntryFilter $filter, ?int $beforeId = null, int $limit = 50): array
    {
        $this->entities->assertExists($entityId);

        if ($limit < 1 || $limit > self::MAX_PAGE) {
            throw new ValidationException(sprintf('The page size is between 1 and %d.', self::MAX_PAGE));
        }

        return $this->entries->search($entityId, $filter, $beforeId, $limit);
    }
}
