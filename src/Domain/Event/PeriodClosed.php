<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Domain\Event;

use CognitioTechny\Contabil\Domain\Calendar\Period;

final class PeriodClosed
{
    public function __construct(
        public readonly int $entityId,
        public readonly Period $period,
        public readonly ?string $userId,
    ) {
    }
}
