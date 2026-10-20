<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Tests\Application\Defaults;

use CognitioTechny\Contabil\Application\Defaults\InMemoryStorage;
use CognitioTechny\Contabil\Application\Ports\StorageInterface;
use CognitioTechny\Contabil\Testing\Contract\StorageContractTest as Contract;

/**
 * @internal
 */
final class InMemoryStorageTest extends Contract
{
    protected function createSubject(): StorageInterface
    {
        return new InMemoryStorage();
    }
}
