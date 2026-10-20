<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Tests\Application\Defaults;

use CognitioTechny\Contabil\Application\Defaults\DenyAllAuthorizer;
use CognitioTechny\Contabil\Application\Ports\AuthorizerInterface;
use CognitioTechny\Contabil\Testing\Contract\AuthorizerContractTest as Contract;

/**
 * @internal
 */
final class DenyAllAuthorizerTest extends Contract
{
    protected function createSubject(): AuthorizerInterface
    {
        return new DenyAllAuthorizer();
    }
}
