<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Period;

use CognitioTechny\Contabil\Application\Entry\CreateEntry;
use CognitioTechny\Contabil\Application\Ports\CurrentUserInterface;
use CognitioTechny\Contabil\Application\Repository\TransactionRunnerInterface;
use CognitioTechny\Contabil\Application\Support\EventPublisher;
use CognitioTechny\Contabil\Domain\Calendar\FiscalYear;
use CognitioTechny\Contabil\Domain\Calendar\FiscalYearRepositoryInterface;
use CognitioTechny\Contabil\Domain\Calendar\Period;
use CognitioTechny\Contabil\Domain\Calendar\YearStatus;
use CognitioTechny\Contabil\Domain\Entry\EntryRepositoryInterface;
use CognitioTechny\Contabil\Domain\Event\PeriodClosed;
use CognitioTechny\Contabil\Domain\Event\PeriodReopened;
use CognitioTechny\Contabil\Domain\Exception\ConflictException;
use CognitioTechny\Contabil\Domain\Exception\NotFoundException;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;

/**
 * Closes and reopens months. Closing needs every draft of the month posted or
 * deleted; reopening needs a reason and leaves an audit event.
 */
final class ManagePeriod
{
    public function __construct(
        private readonly FiscalYearRepositoryInterface $years,
        private readonly EntryRepositoryInterface $entries,
        private readonly TransactionRunnerInterface $transactions,
        private readonly CurrentUserInterface $user,
        private readonly EventPublisher $events,
    ) {
    }

    /**
     * @throws ConflictException The period is not open, or drafts are pending.
     * @throws NotFoundException
     */
    public function close(int $entityId, int $periodId): Period
    {
        $period = $this->transactions->run(function () use ($entityId, $periodId): Period {
            // Waits for postings in flight, so no entry slips into a period being closed.
            $this->entries->lockEntity($entityId);

            [, $period] = $this->require($entityId, $periodId);

            $drafts = $this->entries->countDrafts($entityId, $period->startDate, $period->endDate);

            if ($drafts > 0) {
                throw new ConflictException(sprintf('%d draft entries are pending in the period; post or delete them first.', $drafts));
            }

            $closed = $period->close();
            $this->years->savePeriod($closed);

            return $closed;
        });

        $this->events->publish([new PeriodClosed($entityId, $period, CreateEntry::userId($this->user))]);

        return $period;
    }

    /**
     * @throws ConflictException   The period is not closed, or its fiscal year is closed.
     * @throws NotFoundException
     * @throws ValidationException No reason was given.
     */
    public function reopen(int $entityId, int $periodId, string $reason): Period
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new ValidationException('A reason is required to reopen a period, up to 500 characters.');
        }

        $period = $this->transactions->run(function () use ($entityId, $periodId): Period {
            $this->entries->lockEntity($entityId);

            [$year, $period] = $this->require($entityId, $periodId);

            if ($year->status === YearStatus::Closed) {
                throw new ConflictException('The fiscal year is closed; reopen the year first.');
            }

            $reopened = $period->reopen();
            $this->years->savePeriod($reopened);

            return $reopened;
        });

        $this->events->publish([new PeriodReopened($entityId, $period, $reason, CreateEntry::userId($this->user))]);

        return $period;
    }

    /**
     * @return array{0: FiscalYear, 1: Period}
     */
    private function require(int $entityId, int $periodId): array
    {
        return $this->years->findPeriod($entityId, $periodId)
            ?? throw new NotFoundException(sprintf('Period %d does not exist in this entity.', $periodId));
    }
}
