<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Entry;

use CognitioTechny\Contabil\Application\Repository\TransactionRunnerInterface;
use CognitioTechny\Contabil\Application\Support\DateInput;
use CognitioTechny\Contabil\Domain\Entry\EntryRepositoryInterface;
use CognitioTechny\Contabil\Domain\Entry\JournalEntry;
use CognitioTechny\Contabil\Domain\Entry\PostingPolicy;
use CognitioTechny\Contabil\Domain\Exception\ConflictException;
use CognitioTechny\Contabil\Domain\Exception\NotFoundException;

/**
 * Edits and discards drafts. A posted entry is never touched by either.
 */
final class ManageDraft
{
    public function __construct(
        private readonly EntryRepositoryInterface $entries,
        private readonly PostingPolicy $policy,
        private readonly TransactionRunnerInterface $transactions,
    ) {
    }

    /**
     * Replaces the content of a draft. The idempotency key and the user stay.
     *
     * @throws ConflictException The entry is not a draft.
     * @throws NotFoundException
     */
    public function update(int $entityId, int $entryId, EntryData $data): JournalEntry
    {
        return $this->transactions->run(function () use ($entityId, $entryId, $data): JournalEntry {
            $draft = $this->requireDraft($entityId, $entryId);

            $revised = $draft->revise(
                DateInput::parse($data->accrualDate, 'accrual_date'),
                $data->description,
                $data->toLines(),
                DateInput::parseOptional($data->cashDate, 'cash_date'),
                $data->sourceType,
                $data->sourceId,
            );

            $this->policy->assertAccounts($revised);

            return $this->entries->replaceDraft($revised);
        });
    }

    /**
     * @throws ConflictException The entry is not a draft.
     * @throws NotFoundException
     */
    public function delete(int $entityId, int $entryId): void
    {
        $this->transactions->run(function () use ($entityId, $entryId): void {
            $this->requireDraft($entityId, $entryId);
            $this->entries->deleteDraft($entityId, $entryId);
        });
    }

    private function requireDraft(int $entityId, int $entryId): JournalEntry
    {
        $entry = $this->entries->find($entityId, $entryId);

        if ($entry === null) {
            throw new NotFoundException(sprintf('Entry %d does not exist in this entity.', $entryId));
        }

        if ($entry->status->isInLedger()) {
            throw new ConflictException(sprintf('Entry %d is %s and can no longer be changed or deleted; reverse it instead.', $entryId, $entry->status->value));
        }

        return $entry;
    }
}
