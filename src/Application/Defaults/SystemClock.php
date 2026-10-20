<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Defaults;

use CognitioTechny\Contabil\Application\Ports\ClockInterface;
use DateTimeImmutable;
use DateTimeZone;

final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
