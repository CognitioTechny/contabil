<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Domain\Event;

use CognitioTechny\Contabil\Domain\Calendar\FiscalYear;
use CognitioTechny\Contabil\Domain\Entry\JournalEntry;

final class FiscalYearReopened
{
    public function __construct(
        public readonly FiscalYear $year,
        public readonly ?JournalEntry $reversalOfClosing,
        public readonly string $reason,
        public readonly ?string $userId,
    ) {
    }
}
