<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Domain\Event;

use CognitioTechny\Contabil\Domain\Calendar\FiscalYear;
use CognitioTechny\Contabil\Domain\Entry\JournalEntry;

final class FiscalYearClosed
{
    public function __construct(
        public readonly FiscalYear $year,
        public readonly ?JournalEntry $closingEntry,
        public readonly ?string $userId,
    ) {
    }
}
