<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Entry;

use CognitioTechny\Contabil\Application\Ports\CurrentUserInterface;
use CognitioTechny\Contabil\Application\Repository\EntityGuard;
use CognitioTechny\Contabil\Application\Repository\TransactionRunnerInterface;
use CognitioTechny\Contabil\Application\Support\EventPublisher;
use CognitioTechny\Contabil\Domain\Exception\ContabilException;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;

final class PostBatch
{
    public const MAX_ENTRIES = 500;

    public function __construct(
        private readonly EntityGuard $entities,
        private readonly EntryRecorder $recorder,
        private readonly TransactionRunnerInterface $transactions,
        private readonly CurrentUserInterface $user,
        private readonly EventPublisher $events,
    ) {
    }

    /**
     * Creates and posts several entries atomically: if any one fails, none is
     * kept. Idempotency keys apply to each entry, so a whole batch can be
     * retried safely. A failure keeps its exception type and names the entry.
     *
     * @param list<EntryData> $batch
     *
     * @return list<EntryResult> In the order received.
     */
    public function execute(int $entityId, array $batch): array
    {
        $this->entities->assertExists($entityId);

        if ($batch === [] || count($batch) > self::MAX_ENTRIES) {
            throw new ValidationException(sprintf('A batch has 1 to %d entries.', self::MAX_ENTRIES));
        }

        [$results, $events] = $this->transactions->run(function () use ($entityId, $batch): array {
            $results = [];
            $events  = [];

            foreach ($batch as $index => $data) {
                try {
                    [$result, $produced] = $this->recorder->record($entityId, $data, CreateEntry::userId($this->user), true);
                } catch (ContabilException $e) {
                    throw new $e(sprintf('Entry %d of the batch: %s', $index + 1, $e->getMessage()), 0, $e);
                }

                $results[] = $result;
                $events    = [...$events, ...$produced];
            }

            return [$results, $events];
        });

        $this->events->publish($events);

        return $results;
    }
}
