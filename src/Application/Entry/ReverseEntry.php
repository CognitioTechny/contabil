<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Entry;

use CognitioTechny\Contabil\Application\Ports\CurrentUserInterface;
use CognitioTechny\Contabil\Application\Repository\TransactionRunnerInterface;
use CognitioTechny\Contabil\Application\Support\DateInput;
use CognitioTechny\Contabil\Application\Support\EventPublisher;

final class ReverseEntry
{
    public function __construct(
        private readonly EntryReverser $reverser,
        private readonly TransactionRunnerInterface $transactions,
        private readonly CurrentUserInterface $user,
        private readonly EventPublisher $events,
    ) {
    }

    /**
     * Reverses a posted entry once. The reversal is a new posted entry with the
     * lines on the opposite sides, linked to the original; the original becomes
     * `REVERSED`. To correct an entry, reverse it and post a new one.
     *
     * @param string|null $date `YYYY-MM-DD` accrual date of the reversal; defaults to the original's date.
     *                          Pass a later date when the original's period is closed.
     */
    public function execute(int $entityId, int $entryId, string $reason, ?string $date = null): ReversalResult
    {
        [$result, $events] = $this->transactions->run(fn (): array => $this->reverser->reverse(
            $entityId,
            $entryId,
            $reason,
            CreateEntry::userId($this->user),
            DateInput::parseOptional($date, 'date'),
        ));

        $this->events->publish($events);

        return $result;
    }
}
