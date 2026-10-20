<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Ports;

use DateTimeImmutable;

/**
 * Source of "now", so use cases and tests never read the system clock directly.
 */
interface ClockInterface
{
    /**
     * Must return a timezone-aware instant, in UTC.
     */
    public function now(): DateTimeImmutable;
}
