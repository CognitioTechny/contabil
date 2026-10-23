<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Domain\Entry;

use CognitioTechny\Contabil\Domain\Calendar\FiscalYear;
use CognitioTechny\Contabil\Domain\Calendar\FiscalYearRepositoryInterface;
use CognitioTechny\Contabil\Domain\Calendar\Period;
use CognitioTechny\Contabil\Domain\Calendar\PeriodStatus;
use CognitioTechny\Contabil\Domain\Chart\AccountRepositoryInterface;
use CognitioTechny\Contabil\Domain\Exception\ConflictException;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;
use DateTimeImmutable;

/**
 * Rules that tie an entry to the chart and the calendar: lines go on active
 * leaf accounts of the same entity, and the accrual date falls in an open
 * period. It only reads.
 */
final class PostingPolicy
{
    public function __construct(
        private readonly AccountRepositoryInterface $accounts,
        private readonly FiscalYearRepositoryInterface $years,
    ) {
    }

    /**
     * @throws ValidationException An account is missing, a group, or inactive.
     */
    public function assertAccounts(JournalEntry $entry): void
    {
        foreach ($entry->lines as $line) {
            $account = $this->accounts->find($entry->entityId, $line->accountId);

            if ($account === null) {
                throw new ValidationException(sprintf('Line %d: account %d does not exist in this entity.', $line->position, $line->accountId));
            }

            if (! $account->acceptsEntries) {
                throw new ValidationException(sprintf('Line %d: account %s is a group and does not accept entries.', $line->position, $account->code));
            }

            if (! $account->active) {
                throw new ValidationException(sprintf('Line %d: account %s is inactive.', $line->position, $account->code));
            }
        }
    }

    /**
     * The year and period an entry dated `$date` is posted in.
     *
     * @return array{0: FiscalYear, 1: Period}
     *
     * @throws ConflictException   The period is not open.
     * @throws ValidationException The calendar has no period for the date.
     */
    public function openPeriodFor(int $entityId, DateTimeImmutable $date): array
    {
        $found = $this->years->periodContaining($entityId, $date);

        if ($found === null) {
            throw new ValidationException(sprintf('No fiscal period exists for %s; create the fiscal year first.', $date->format('Y-m-d')));
        }

        [$year, $period] = $found;

        if ($period->status !== PeriodStatus::Open) {
            throw new ConflictException(sprintf(
                'The period %s to %s is %s and accepts no entries.',
                $period->startDate->format('Y-m-d'),
                $period->endDate->format('Y-m-d'),
                $period->status->value,
            ));
        }

        return [$year, $period];
    }
}
