<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Domain\Event;

use CognitioTechny\Contabil\Domain\Entry\JournalEntry;

/**
 * An entry entered the ledger. Dispatched after the transaction commits.
 */
final class EntryPosted
{
    public function __construct(public readonly JournalEntry $entry)
    {
    }
}
