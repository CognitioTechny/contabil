<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Testing\Contract;

use CodeIgniter\Test\CIUnitTestCase;
use CognitioTechny\Contabil\Application\Ports\EventDispatcherInterface;
use stdClass;

/**
 * Contract every EventDispatcherInterface implementation must satisfy.
 */
abstract class EventDispatcherContractTest extends CIUnitTestCase
{
    abstract protected function createSubject(): EventDispatcherInterface;

    public function testDispatchReturnsTheSameEventObject(): void
    {
        $event = new stdClass();

        $this->assertSame($event, $this->createSubject()->dispatch($event));
    }

    public function testDispatchWithoutListenersIsNotAnError(): void
    {
        $this->assertInstanceOf(stdClass::class, $this->createSubject()->dispatch(new stdClass()));
    }
}
