<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Domain\Event;

use CognitioTechny\Contabil\Domain\Entry\JournalEntry;

/**
 * An entry was cancelled by a reversal.
 */
final class EntryReversed
{
    public function __construct(
        public readonly JournalEntry $original,
        public readonly JournalEntry $reversal,
        public readonly string $reason,
    ) {
    }
}
