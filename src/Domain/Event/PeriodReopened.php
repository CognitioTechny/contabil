<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Domain\Event;

use CognitioTechny\Contabil\Domain\Calendar\Period;

/**
 * A closed period accepts entries again. The reason is part of the audit trail
 * the host keeps.
 */
final class PeriodReopened
{
    public function __construct(
        public readonly int $entityId,
        public readonly Period $period,
        public readonly string $reason,
        public readonly ?string $userId,
    ) {
    }
}
