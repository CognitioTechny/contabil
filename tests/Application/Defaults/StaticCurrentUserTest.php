<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Tests\Application\Defaults;

use CognitioTechny\Contabil\Application\Defaults\StaticCurrentUser;
use CognitioTechny\Contabil\Application\Ports\CurrentUserInterface;
use CognitioTechny\Contabil\Testing\Contract\CurrentUserContractTest as Contract;

/**
 * @internal
 */
final class StaticCurrentUserTest extends Contract
{
    protected function createSubject(): CurrentUserInterface
    {
        return new StaticCurrentUser(42);
    }
}
