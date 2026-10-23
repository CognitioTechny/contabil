<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Entry;

use CognitioTechny\Contabil\Domain\Entry\JournalEntry;

/**
 * Outcome of creating an entry. `created` is false when the idempotency key
 * matched an entry that already existed.
 */
final class EntryResult
{
    public function __construct(
        public readonly JournalEntry $entry,
        public readonly bool $created,
    ) {
    }
}
