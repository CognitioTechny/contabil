<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Testing\Contract;

use CodeIgniter\Test\CIUnitTestCase;
use CognitioTechny\Contabil\Application\Ports\CurrentEntityInterface;

/**
 * Contract every CurrentEntityInterface implementation must satisfy.
 */
abstract class CurrentEntityContractTest extends CIUnitTestCase
{
    abstract protected function createSubject(): CurrentEntityInterface;

    public function testIdIsIntStringOrNull(): void
    {
        $this->assertTrue(in_array(gettype($this->createSubject()->id()), ['integer', 'string', 'NULL'], true));
    }

    public function testIdIsStableWithinARequest(): void
    {
        $subject = $this->createSubject();

        $this->assertSame($subject->id(), $subject->id());
    }
}
