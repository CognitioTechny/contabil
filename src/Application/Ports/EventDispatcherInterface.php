<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Ports;

use Psr\EventDispatcher\EventDispatcherInterface as Psr14Dispatcher;

/**
 * Port for domain events. It is exactly PSR-14, so any PSR-14 dispatcher can
 * be plugged in unchanged.
 *
 * Events are dispatched after the posting transaction commits, never inside it.
 * A listener failure must not roll back an accounting write.
 */
interface EventDispatcherInterface extends Psr14Dispatcher
{
}
