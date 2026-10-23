<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Domain\Event;

use CognitioTechny\Contabil\Domain\Entry\Settlement;

/**
 * A settlement entry was linked to the entry it pays or collects.
 */
final class EntrySettled
{
    public function __construct(
        public readonly int $entityId,
        public readonly Settlement $settlement,
    ) {
    }
}
