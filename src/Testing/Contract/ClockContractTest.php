<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Testing\Contract;

use CodeIgniter\Test\CIUnitTestCase;
use CognitioTechny\Contabil\Application\Ports\ClockInterface;

/**
 * Contract every ClockInterface implementation must satisfy.
 */
abstract class ClockContractTest extends CIUnitTestCase
{
    abstract protected function createSubject(): ClockInterface;

    public function testNowIsInUtc(): void
    {
        $this->assertSame(0, $this->createSubject()->now()->getOffset());
    }

    public function testTimeNeverMovesBackwards(): void
    {
        $subject = $this->createSubject();
        $first   = $subject->now();
        $second  = $subject->now();

        $this->assertGreaterThanOrEqual($first, $second);
    }
}
