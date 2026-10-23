<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Entry;

use CognitioTechny\Contabil\Domain\Entry\JournalEntry;

final class ReversalResult
{
    public function __construct(
        public readonly JournalEntry $original,
        public readonly JournalEntry $reversal,
    ) {
    }
}
