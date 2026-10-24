<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Domain\Event;

use CognitioTechny\Contabil\Domain\Calendar\FiscalYear;

final class FiscalYearOpened
{
    public function __construct(
        public readonly FiscalYear $year,
        public readonly FiscalYear $previous,
        public readonly ?string $userId,
    ) {
    }
}
