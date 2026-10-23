<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Entry;

use CognitioTechny\Contabil\Application\Ports\CurrentUserInterface;
use CognitioTechny\Contabil\Application\Repository\EntityGuard;
use CognitioTechny\Contabil\Application\Repository\TransactionRunnerInterface;
use CognitioTechny\Contabil\Application\Support\EventPublisher;

final class CreateEntry
{
    public function __construct(
        private readonly EntityGuard $entities,
        private readonly EntryRecorder $recorder,
        private readonly TransactionRunnerInterface $transactions,
        private readonly CurrentUserInterface $user,
        private readonly EventPublisher $events,
    ) {
    }

    /**
     * Stores a draft, or posts it right away. Sending the same idempotency key
     * again returns the stored entry with `created = false` and changes nothing;
     * a key reused with different content is a conflict.
     */
    public function execute(int $entityId, EntryData $data, bool $post = false): EntryResult
    {
        $this->entities->assertExists($entityId);

        [$result, $events] = $this->transactions->run(
            fn (): array => $this->recorder->record($entityId, $data, self::userId($this->user), $post),
        );

        $this->events->publish($events);

        return $result;
    }

    public static function userId(CurrentUserInterface $user): ?string
    {
        $id = $user->id();

        return $id === null ? null : (string) $id;
    }
}
