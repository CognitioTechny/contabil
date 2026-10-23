<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Period;

use CognitioTechny\Contabil\Application\Entry\CreateEntry;
use CognitioTechny\Contabil\Application\Entry\EntryPoster;
use CognitioTechny\Contabil\Application\Entry\EntryReverser;
use CognitioTechny\Contabil\Application\Ports\CurrentUserInterface;
use CognitioTechny\Contabil\Application\Reports\ReportReaderInterface;
use CognitioTechny\Contabil\Application\Repository\TransactionRunnerInterface;
use CognitioTechny\Contabil\Application\Support\EventPublisher;
use CognitioTechny\Contabil\Domain\Calendar\FiscalYear;
use CognitioTechny\Contabil\Domain\Calendar\FiscalYearRepositoryInterface;
use CognitioTechny\Contabil\Domain\Calendar\PeriodStatus;
use CognitioTechny\Contabil\Domain\Calendar\YearStatus;
use CognitioTechny\Contabil\Domain\Chart\AccountRepositoryInterface;
use CognitioTechny\Contabil\Domain\Chart\AccountType;
use CognitioTechny\Contabil\Domain\Entry\EntryFilter;
use CognitioTechny\Contabil\Domain\Entry\EntryRepositoryInterface;
use CognitioTechny\Contabil\Domain\Entry\EntryStatus;
use CognitioTechny\Contabil\Domain\Entry\JournalEntry;
use CognitioTechny\Contabil\Domain\Entry\Line;
use CognitioTechny\Contabil\Domain\Entry\Side;
use CognitioTechny\Contabil\Domain\Event\EntryPosted;
use CognitioTechny\Contabil\Domain\Event\FiscalYearClosed;
use CognitioTechny\Contabil\Domain\Event\FiscalYearReopened;
use CognitioTechny\Contabil\Domain\Event\PeriodClosed;
use CognitioTechny\Contabil\Domain\Event\PeriodReopened;
use CognitioTechny\Contabil\Domain\Exception\ConflictException;
use CognitioTechny\Contabil\Domain\Exception\NotFoundException;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;

/**
 * Closes a fiscal year by posting the result-closing entry, and reopens it by
 * reversing that entry.
 */
final class ManageFiscalYear
{
    public const CLOSING_SOURCE = 'contabil.fiscal-year-closing';

    public function __construct(
        private readonly FiscalYearRepositoryInterface $years,
        private readonly AccountRepositoryInterface $accounts,
        private readonly EntryRepositoryInterface $entries,
        private readonly ReportReaderInterface $reader,
        private readonly EntryPoster $poster,
        private readonly EntryReverser $reverser,
        private readonly TransactionRunnerInterface $transactions,
        private readonly CurrentUserInterface $user,
        private readonly EventPublisher $events,
        private readonly ?string $resultAccountCode = null,
    ) {
    }

    /**
     * Closes the year.
     *
     * Every period but the last must already be closed. The revenue and
     * expense accounts are then zeroed by one entry dated the last day of the
     * year, which moves the net result to the result account (an equity
     * account); the last period and the year are closed. A year whose
     * accounts already net to zero closes without an entry.
     *
     * @param int|null $resultAccountId Overrides the configured result account.
     *
     * @return array{0: FiscalYear, 1: JournalEntry|null} The closed year and the closing entry, if one was needed.
     *
     * @throws ConflictException   The year is closed, periods or drafts are pending.
     * @throws NotFoundException
     * @throws ValidationException No usable result account when one is needed.
     */
    public function close(int $entityId, int $yearId, ?int $resultAccountId = null): array
    {
        [$year, $closing, $events] = $this->transactions->run(function () use ($entityId, $yearId, $resultAccountId): array {
            $this->entries->lockEntity($entityId);

            $year = $this->requireYear($entityId, $yearId);

            if ($year->status !== YearStatus::Open) {
                throw new ConflictException('The fiscal year is already closed.');
            }

            $periods = $this->years->periodsOf($yearId);
            $last    = $periods[array_key_last($periods)];

            foreach (array_slice($periods, 0, -1) as $period) {
                if ($period->status === PeriodStatus::Open) {
                    throw new ConflictException(sprintf('Close the period starting %s before closing the year.', $period->startDate->format('Y-m-d')));
                }
            }

            $drafts = $this->entries->countDrafts($entityId, $year->startDate, $year->endDate);

            if ($drafts > 0) {
                throw new ConflictException(sprintf('%d draft entries are pending in the year; post or delete them first.', $drafts));
            }

            $events  = [];
            $closing = null;
            $lines   = $this->closingLines($entityId, $year, $resultAccountId);

            if ($lines !== []) {
                if ($last->status !== PeriodStatus::Open) {
                    throw new ConflictException('The last period is closed; reopen it so the closing entry can be posted.');
                }

                $userId = CreateEntry::userId($this->user);
                $draft  = JournalEntry::draft(
                    $entityId,
                    $year->endDate,
                    'Closing of fiscal year ' . $year->label,
                    $lines,
                    null,
                    self::CLOSING_SOURCE,
                    (string) $yearId,
                    null,
                    $userId,
                );
                $closing  = $this->poster->post($this->entries->insert($draft));
                $events[] = new EntryPosted($closing);
            }

            if ($last->status === PeriodStatus::Open) {
                $lastClosed = $last->close();
                $this->years->savePeriod($lastClosed);
                $events[] = new PeriodClosed($entityId, $lastClosed, CreateEntry::userId($this->user));
            }

            $closedYear = $year->close($this->years->periodsOf($yearId));
            $this->years->saveYear($closedYear);
            $events[] = new FiscalYearClosed($closedYear, $closing, CreateEntry::userId($this->user));

            return [$closedYear, $closing, $events];
        });

        $this->events->publish($events);

        return [$year, $closing];
    }

    /**
     * Reopens a closed year: the closing entry is reversed and the last period
     * opens again. Other periods stay as they are. Not allowed while the next
     * fiscal year is closed, since its opening balances depend on this one.
     *
     * @throws ConflictException   The year is open, the next year is closed, or the last period is locked.
     * @throws NotFoundException
     * @throws ValidationException No reason was given.
     */
    public function reopen(int $entityId, int $yearId, string $reason): FiscalYear
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new ValidationException('A reason is required to reopen a fiscal year, up to 500 characters.');
        }

        [$year, $events] = $this->transactions->run(function () use ($entityId, $yearId, $reason): array {
            $this->entries->lockEntity($entityId);

            $year = $this->requireYear($entityId, $yearId);

            if ($year->status !== YearStatus::Closed) {
                throw new ConflictException('Only a closed fiscal year can be reopened.');
            }

            foreach ($this->years->forEntity($entityId) as $other) {
                if ($other->startDate > $year->endDate && $other->status === YearStatus::Closed) {
                    throw new ConflictException(sprintf('The fiscal year "%s" after this one is closed; reopen it first.', $other->label));
                }
            }

            $periods = $this->years->periodsOf($yearId);
            $last    = $periods[array_key_last($periods)];
            $userId  = CreateEntry::userId($this->user);
            $events  = [];

            if ($last->status === PeriodStatus::Locked) {
                throw new ConflictException('The last period is locked and cannot be reopened.');
            }

            if ($last->status === PeriodStatus::Closed) {
                $reopened = $last->reopen();
                $this->years->savePeriod($reopened);
                $events[] = new PeriodReopened($entityId, $reopened, $reason, $userId);
            }

            $closingEntry = $this->closingEntryOf($entityId, $yearId);
            $reversal     = null;

            if ($closingEntry !== null) {
                [$result, $produced] = $this->reverser->reverse($entityId, (int) $closingEntry->id, 'Reopening of fiscal year ' . $year->label . ': ' . $reason, $userId);
                $reversal            = $result->reversal;
                $events              = [...$events, ...$produced];
            }

            $reopenedYear = $year->reopen();
            $this->years->saveYear($reopenedYear);
            $events[] = new FiscalYearReopened($reopenedYear, $reversal, $reason, $userId);

            return [$reopenedYear, $events];
        });

        $this->events->publish($events);

        return $year;
    }

    /**
     * Lines that zero every revenue and expense account over the year and move
     * the net result to the result account.
     *
     * @return list<Line> Empty when nothing needs closing.
     */
    private function closingLines(int $entityId, FiscalYear $year, ?int $resultAccountId): array
    {
        $sums  = $this->reader->accrualSums($entityId, $year->startDate, $year->endDate);
        $lines = [];
        $net   = 0;

        foreach ($this->accounts->all($entityId) as $account) {
            if (! $account->acceptsEntries || ! in_array($account->type, [AccountType::Revenue, AccountType::Expense], true)) {
                continue;
            }

            [$debits, $credits] = $sums[(int) $account->id] ?? [0, 0];
            $balance            = $debits - $credits;

            if ($balance === 0) {
                continue;
            }

            $lines[] = new Line((int) $account->id, $balance > 0 ? Side::Credit : Side::Debit, abs($balance), 'Closing of ' . $account->code);
            $net += $balance;
        }

        if ($lines === []) {
            return [];
        }

        $result = $this->resultAccount($entityId, $resultAccountId);

        if ($net !== 0) {
            // A debit balance overall is a loss: the result account is debited.
            $lines[] = new Line($result, $net > 0 ? Side::Debit : Side::Credit, abs($net), 'Result of fiscal year ' . $year->label);
        }

        return $lines;
    }

    private function resultAccount(int $entityId, ?int $resultAccountId): int
    {
        $account = $resultAccountId !== null
            ? $this->accounts->find($entityId, $resultAccountId)
            : ($this->resultAccountCode !== null ? $this->accounts->findByCode($entityId, $this->resultAccountCode) : null);

        if ($account === null) {
            throw new ValidationException('Closing a year with revenue or expense needs a result account: pass result_account_id or set resultAccountCode.');
        }

        if ($account->type !== AccountType::Equity || ! $account->acceptsEntries || ! $account->active) {
            throw new ValidationException(sprintf('The result account %s must be an active equity account that accepts entries.', $account->code));
        }

        return (int) $account->id;
    }

    private function closingEntryOf(int $entityId, int $yearId): ?JournalEntry
    {
        $found = $this->entries->search(
            $entityId,
            new EntryFilter(EntryStatus::Posted, null, null, self::CLOSING_SOURCE, (string) $yearId),
            null,
            10,
        );

        foreach ($found as $entry) {
            if (! $entry->isReversal()) {
                return $entry;
            }
        }

        return null;
    }

    private function requireYear(int $entityId, int $yearId): FiscalYear
    {
        return $this->years->find($entityId, $yearId)
            ?? throw new NotFoundException(sprintf('Fiscal year %d does not exist in this entity.', $yearId));
    }
}
