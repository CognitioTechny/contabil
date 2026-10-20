<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Tests\Application\Defaults;

use CognitioTechny\Contabil\Application\Defaults\SystemClock;
use CognitioTechny\Contabil\Application\Ports\ClockInterface;
use CognitioTechny\Contabil\Testing\Contract\ClockContractTest as Contract;

/**
 * @internal
 */
final class SystemClockTest extends Contract
{
    protected function createSubject(): ClockInterface
    {
        return new SystemClock();
    }
}
