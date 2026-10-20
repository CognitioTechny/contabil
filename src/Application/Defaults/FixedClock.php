<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Defaults;

use CognitioTechny\Contabil\Application\Ports\ClockInterface;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Clock frozen at a given instant; meant for tests.
 */
final class FixedClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(DateTimeImmutable $now)
    {
        $this->now = $now->setTimezone(new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
