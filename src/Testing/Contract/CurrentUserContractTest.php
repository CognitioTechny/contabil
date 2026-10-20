<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Testing\Contract;

use CodeIgniter\Test\CIUnitTestCase;
use CognitioTechny\Contabil\Application\Ports\CurrentUserInterface;

/**
 * Contract every CurrentUserInterface implementation must satisfy. Extend it
 * in the host test suite and return the adapter from createSubject().
 */
abstract class CurrentUserContractTest extends CIUnitTestCase
{
    abstract protected function createSubject(): CurrentUserInterface;

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
