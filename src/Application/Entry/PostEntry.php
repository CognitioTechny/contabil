<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Entry;

use CognitioTechny\Contabil\Application\Repository\TransactionRunnerInterface;
use CognitioTechny\Contabil\Application\Support\EventPublisher;
use CognitioTechny\Contabil\Domain\Entry\EntryRepositoryInterface;
use CognitioTechny\Contabil\Domain\Entry\JournalEntry;
use CognitioTechny\Contabil\Domain\Event\EntryPosted;
use CognitioTechny\Contabil\Domain\Exception\ConflictException;
use CognitioTechny\Contabil\Domain\Exception\NotFoundException;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;

final class PostEntry
{
    public function __construct(
        private readonly EntryRepositoryInterface $entries,
        private readonly EntryPoster $poster,
        private readonly TransactionRunnerInterface $transactions,
        private readonly EventPublisher $events,
    ) {
    }

    /**
     * Posts a draft: it gets its number and enters the ledger for good.
     *
     * @throws ConflictException   Not a draft, or the period is closed.
     * @throws NotFoundException
     * @throws ValidationException Unbalanced, or an account is not usable.
     */
    public function execute(int $entityId, int $entryId): JournalEntry
    {
        $posted = $this->transactions->run(function () use ($entityId, $entryId): JournalEntry {
            $draft = $this->entries->find($entityId, $entryId);

            if ($draft === null) {
                throw new NotFoundException(sprintf('Entry %d does not exist in this entity.', $entryId));
            }

            return $this->poster->post($draft);
        });

        $this->events->publish([new EntryPosted($posted)]);

        return $posted;
    }
}
