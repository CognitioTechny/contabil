<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Entry;

use CognitioTechny\Contabil\Application\Repository\TransactionRunnerInterface;
use CognitioTechny\Contabil\Application\Support\DateInput;
use CognitioTechny\Contabil\Application\Support\EventPublisher;
use CognitioTechny\Contabil\Domain\Entry\EntryRepositoryInterface;
use CognitioTechny\Contabil\Domain\Entry\EntryStatus;
use CognitioTechny\Contabil\Domain\Entry\JournalEntry;
use CognitioTechny\Contabil\Domain\Entry\Settlement;
use CognitioTechny\Contabil\Domain\Event\EntrySettled;
use CognitioTechny\Contabil\Domain\Exception\ConflictException;
use CognitioTechny\Contabil\Domain\Exception\NotFoundException;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;

final class SettleEntry
{
    public function __construct(
        private readonly EntryRepositoryInterface $entries,
        private readonly TransactionRunnerInterface $transactions,
        private readonly EventPublisher $events,
    ) {
    }

    /**
     * Links a posted settlement entry (a payment or collection) to the posted
     * entry it settles, for `$amount` minor units. Partial settlements are
     * allowed until the settled entry's total is covered; the cash basis uses
     * these links to recognize revenue and expense.
     *
     * An entry that already moved cash on its own (it has a cash date) cannot
     * be settled, and neither entry may be a reversal.
     *
     * @param string|null $date `YYYY-MM-DD`; defaults to the settlement entry's cash date, then its accrual date.
     */
    public function execute(int $entityId, int $settlementEntryId, int $settledEntryId, int $amount, ?string $date = null): Settlement
    {
        if ($amount < 1) {
            throw new ValidationException('The settled amount must be a positive integer in minor units.');
        }

        if ($settlementEntryId === $settledEntryId) {
            throw new ValidationException('An entry cannot settle itself.');
        }

        $settlement = $this->transactions->run(function () use ($entityId, $settlementEntryId, $settledEntryId, $amount, $date): Settlement {
            $this->entries->lockEntity($entityId);

            $paying  = $this->requirePosted($entityId, $settlementEntryId);
            $settled = $this->requirePosted($entityId, $settledEntryId);

            if ($paying->isReversal() || $settled->isReversal()) {
                throw new ConflictException('A reversal cannot take part in a settlement.');
            }

            if ($settled->cashDate !== null) {
                throw new ConflictException('The entry already moved cash on its own date and cannot be settled.');
            }

            $open = $settled->totalDebits() - array_sum(array_map(
                static fn (Settlement $s): int => $s->amount,
                $this->entries->activeSettlementsOf($settledEntryId),
            ));

            if ($amount > $open) {
                throw new ConflictException(sprintf('The amount exceeds what is still open on entry %d (%d).', $settledEntryId, $open));
            }

            $capacity = $paying->totalDebits() - $this->entries->amountSettledBy($settlementEntryId);

            if ($amount > $capacity) {
                throw new ConflictException(sprintf('The settlement entry %d has only %d left to apply.', $settlementEntryId, $capacity));
            }

            return $this->entries->insertSettlement(new Settlement(
                null,
                $settlementEntryId,
                $settledEntryId,
                $amount,
                DateInput::parseOptional($date, 'date') ?? $paying->cashDate ?? $paying->accrualDate,
            ));
        });

        $this->events->publish([new EntrySettled($entityId, $settlement)]);

        return $settlement;
    }

    private function requirePosted(int $entityId, int $entryId): JournalEntry
    {
        $entry = $this->entries->find($entityId, $entryId)
            ?? throw new NotFoundException(sprintf('Entry %d does not exist in this entity.', $entryId));

        if ($entry->status !== EntryStatus::Posted) {
            throw new ConflictException(sprintf('Entry %d is %s; only a posted entry takes part in a settlement.', $entryId, $entry->status->value));
        }

        return $entry;
    }
}
