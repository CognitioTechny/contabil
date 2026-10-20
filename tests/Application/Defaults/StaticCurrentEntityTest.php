<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Tests\Application\Defaults;

use CognitioTechny\Contabil\Application\Defaults\StaticCurrentEntity;
use CognitioTechny\Contabil\Application\Ports\CurrentEntityInterface;
use CognitioTechny\Contabil\Testing\Contract\CurrentEntityContractTest as Contract;

/**
 * @internal
 */
final class StaticCurrentEntityTest extends Contract
{
    protected function createSubject(): CurrentEntityInterface
    {
        return new StaticCurrentEntity('entity-a');
    }
}
