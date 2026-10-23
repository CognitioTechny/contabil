<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Support;

use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * Hands domain events to the PSR-14 dispatcher once the transaction that
 * produced them has committed. A failing listener never undoes an accounting
 * write: its exception is kept in {@see failures()} instead of being thrown.
 */
final class EventPublisher
{
    /**
     * @var list<Throwable>
     */
    private array $failures = [];

    public function __construct(private readonly EventDispatcherInterface $dispatcher)
    {
    }

    /**
     * @param list<object> $events
     */
    public function publish(array $events): void
    {
        foreach ($events as $event) {
            try {
                $this->dispatcher->dispatch($event);
            } catch (Throwable $e) {
                $this->failures[] = $e;
            }
        }
    }

    /**
     * @return list<Throwable> Listener failures seen so far.
     */
    public function failures(): array
    {
        return $this->failures;
    }
}
