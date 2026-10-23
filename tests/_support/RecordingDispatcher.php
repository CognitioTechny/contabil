<?php

declare(strict_types=1);

namespace Tests\Support;

use Psr\EventDispatcher\EventDispatcherInterface;
use ReflectionClass;
use RuntimeException;

/**
 * PSR-14 dispatcher that remembers every event, and can be told to fail.
 */
final class RecordingDispatcher implements EventDispatcherInterface
{
    /**
     * @var list<object>
     */
    public array $events = [];

    public bool $failing = false;

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        if ($this->failing) {
            throw new RuntimeException('listener failed');
        }

        return $event;
    }

    /**
     * @return list<string> Short class names, in dispatch order.
     */
    public function names(): array
    {
        return array_map(static fn (object $e): string => (new ReflectionClass($e))->getShortName(), $this->events);
    }
}
