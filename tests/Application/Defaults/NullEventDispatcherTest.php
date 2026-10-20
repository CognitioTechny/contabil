<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Tests\Application\Defaults;

use CognitioTechny\Contabil\Application\Defaults\NullEventDispatcher;
use CognitioTechny\Contabil\Application\Ports\EventDispatcherInterface;
use CognitioTechny\Contabil\Testing\Contract\EventDispatcherContractTest as Contract;

/**
 * @internal
 */
final class NullEventDispatcherTest extends Contract
{
    protected function createSubject(): EventDispatcherInterface
    {
        return new NullEventDispatcher();
    }
}
