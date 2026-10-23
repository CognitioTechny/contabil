<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Ledger;

use CognitioTechny\Contabil\Application\Repository\EntityGuard;
use CognitioTechny\Contabil\Domain\Entry\EntryHasher;
use CognitioTechny\Contabil\Domain\Entry\EntryRepositoryInterface;
use CognitioTechny\Contabil\Domain\Entry\EntryStatus;

/**
 * Walks the entity's hash chain and reports everything that does not add up:
 * edited or missing entries, a broken link, a gap in the numbering or an
 * unbalanced entry. The hash chain proves internal consistency; a tail edit
 * that also recomputes the last hash needs an external anchor to be caught
 * (store the latest hash from the `EntryPosted` event outside the database).
 */
final class Integrity
{
    public function __construct(
        private readonly EntityGuard $entities,
        private readonly EntryRepositoryInterface $entries,
    ) {
    }

    public function verify(int $entityId): IntegrityReport
    {
        $this->entities->assertExists($entityId);

        $checked      = 0;
        $problems     = [];
        $previousHash = null;
        $position     = 0;
        $lastNumber   = [];

        foreach ($this->entries->chain($entityId) as $entry) {
            $checked++;
            $id = (int) $entry->id;

            if (++$position !== $entry->chainPosition) {
                $problems[] = [$id, sprintf('Chain position is %s, expected %d: an entry is missing or was moved.', var_export($entry->chainPosition, true), $position)];
                $position   = (int) $entry->chainPosition;
            }

            if ($entry->previousHash !== $previousHash) {
                $problems[] = [$id, 'The link to the previous entry is broken.'];
            }

            if (! EntryHasher::matches($entry)) {
                $problems[] = [$id, 'The content does not match its hash: the entry was changed after posting.'];
            }

            if (! $entry->isBalanced() || count($entry->lines) < 2) {
                $problems[] = [$id, 'Debits and credits differ.'];
            }

            if (! in_array($entry->status, [EntryStatus::Posted, EntryStatus::Reversed], true)) {
                $problems[] = [$id, 'A draft is in the chain.'];
            }

            $year = (int) $entry->fiscalYearId;

            if (($lastNumber[$year] ?? 0) + 1 !== $entry->number) {
                $problems[] = [$id, sprintf('Number %s breaks the sequence of its fiscal year (expected %d).', var_export($entry->number, true), ($lastNumber[$year] ?? 0) + 1)];
            }

            $lastNumber[$year] = (int) $entry->number;
            $previousHash      = $entry->hash;
        }

        return new IntegrityReport($checked, $problems);
    }
}
