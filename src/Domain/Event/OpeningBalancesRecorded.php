<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Domain\Event;

use CognitioTechny\Contabil\Domain\Calendar\FiscalYear;
use CognitioTechny\Contabil\Domain\Entry\JournalEntry;

final class OpeningBalancesRecorded
{
    public function __construct(
        public readonly FiscalYear $year,
        public readonly JournalEntry $entry,
        public readonly ?JournalEntry $replaced,
        public readonly ?string $userId,
    ) {
    }
}
