<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Period;

use CognitioTechny\Contabil\Application\Entry\CreateEntry;
use CognitioTechny\Contabil\Application\Entry\EntryPoster;
use CognitioTechny\Contabil\Application\Entry\EntryReverser;
use CognitioTechny\Contabil\Application\Ports\CurrentUserInterface;
use CognitioTechny\Contabil\Application\Repository\TransactionRunnerInterface;
use CognitioTechny\Contabil\Application\Support\EventPublisher;
use CognitioTechny\Contabil\Domain\Calendar\FiscalYear;
use CognitioTechny\Contabil\Domain\Calendar\FiscalYearRepositoryInterface;
use CognitioTechny\Contabil\Domain\Calendar\YearStatus;
use CognitioTechny\Contabil\Domain\Chart\Account;
use CognitioTechny\Contabil\Domain\Chart\AccountRepositoryInterface;
use CognitioTechny\Contabil\Domain\Chart\AccountType;
use CognitioTechny\Contabil\Domain\Entry\EntryFilter;
use CognitioTechny\Contabil\Domain\Entry\EntryRepositoryInterface;
use CognitioTechny\Contabil\Domain\Entry\EntryStatus;
use CognitioTechny\Contabil\Domain\Entry\JournalEntry;
use CognitioTechny\Contabil\Domain\Entry\Line;
use CognitioTechny\Contabil\Domain\Entry\Side;
use CognitioTechny\Contabil\Domain\Event\EntryPosted;
use CognitioTechny\Contabil\Domain\Event\OpeningBalancesRecorded;
use CognitioTechny\Contabil\Domain\Exception\ConflictException;
use CognitioTechny\Contabil\Domain\Exception\NotFoundException;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;
use CognitioTechny\Contabil\Domain\Money;

/**
 * The opening of the books: the balances an entity brings from before it used
 * the package, posted as one entry dated the first day of its first fiscal
 * year. Later years need no such entry, since the ledger carries balances
 * forward on its own.
 */
final class OpeningBalances
{
    public const SOURCE = 'contabil.opening-balances';

    public function __construct(
        private readonly FiscalYearRepositoryInterface $years,
        private readonly AccountRepositoryInterface $accounts,
        private readonly EntryRepositoryInterface $entries,
        private readonly EntryPoster $poster,
        private readonly EntryReverser $reverser,
        private readonly TransactionRunnerInterface $transactions,
        private readonly CurrentUserInterface $user,
        private readonly EventPublisher $events,
    ) {
    }

    /**
     * Posts the opening balances of the entity's first fiscal year.
     *
     * Each balance names an asset, liability or equity leaf account
     * (`account_id` or `account_code`), a `side` (D or C) and an `amount` in
     * minor units; zero amounts are skipped and an account appears once.
     * Debits must equal credits, unless a balancing account (an equity account)
     * is given to take the difference.
     *
     * @param list<array<string, mixed>> $balances
     * @param bool                       $replace  Reverses the current opening entry first, in the same transaction.
     *
     * @throws ConflictException   The year is closed or not the first, its first period is closed, or balances exist and `$replace` is false.
     * @throws NotFoundException
     * @throws ValidationException A balance is invalid, or debits differ from credits without a balancing account.
     */
    public function record(int $entityId, int $yearId, array $balances, ?int $balancingAccountId = null, ?string $description = null, bool $replace = false): JournalEntry
    {
        [$entry, $events] = $this->transactions->run(function () use ($entityId, $yearId, $balances, $balancingAccountId, $description, $replace): array {
            $this->entries->lockEntity($entityId);

            $year = $this->requireYear($entityId, $yearId);

            if ($year->status !== YearStatus::Open) {
                throw new ConflictException('The fiscal year is closed; opening balances go in an open year.');
            }

            foreach ($this->years->forEntity($entityId) as $other) {
                if ($other->startDate < $year->startDate) {
                    throw new ConflictException(sprintf('Opening balances go in the first fiscal year of the entity ("%s"); later years carry the balances of the ledger forward.', $other->label));
                }
            }

            $lines   = $this->lines($entityId, $year, $balances, $balancingAccountId);
            $userId  = CreateEntry::userId($this->user);
            $events  = [];
            $current = $this->currentEntry($entityId, $yearId);

            if ($current !== null) {
                if (! $replace) {
                    throw new ConflictException(sprintf('The opening balances are already posted as entry %d; replace them or reverse that entry first.', (int) $current->number));
                }

                [, $produced] = $this->reverser->reverse($entityId, (int) $current->id, 'Replacement of the opening balances of fiscal year ' . $year->label, $userId);
                $events       = $produced;
            }

            $draft = JournalEntry::draft(
                $entityId,
                $year->startDate,
                $description ?? 'Opening balances of fiscal year ' . $year->label,
                $lines,
                null,
                self::SOURCE,
                (string) $yearId,
                null,
                $userId,
            );
            $posted   = $this->poster->post($this->entries->insert($draft));
            $events[] = new EntryPosted($posted);
            $events[] = new OpeningBalancesRecorded($year, $posted, $current, $userId);

            return [$posted, $events];
        });

        $this->events->publish($events);

        return $entry;
    }

    /**
     * The opening entry of the year in force, or null when none was posted or
     * it was reversed.
     *
     * @throws NotFoundException
     */
    public function current(int $entityId, int $yearId): ?JournalEntry
    {
        $this->requireYear($entityId, $yearId);

        return $this->currentEntry($entityId, $yearId);
    }

    /**
     * @param list<array<string, mixed>> $balances
     *
     * @return list<Line>
     */
    private function lines(int $entityId, FiscalYear $year, array $balances, ?int $balancingAccountId): array
    {
        if (! array_is_list($balances)) {
            throw new ValidationException('balances must be a list.');
        }

        $lines = [];
        $seen  = [];
        $net   = 0;

        foreach ($balances as $index => $row) {
            $number = $index + 1;

            if (! is_array($row)) {
                throw new ValidationException(sprintf('Balance %d must be an object.', $number));
            }

            $amount = $row['amount'] ?? null;

            if (! is_int($amount) || $amount < 0) {
                throw new ValidationException(sprintf('Balance %d: amount must be a non-negative integer number of minor units.', $number));
            }

            try {
                $side = Side::fromInput($row['side'] ?? null);
            } catch (ValidationException $e) {
                throw new ValidationException(sprintf('Balance %d: %s', $number, $e->getMessage()), 0, $e);
            }

            $account = $this->account($entityId, $row, $number);

            if (isset($seen[(int) $account->id])) {
                throw new ValidationException(sprintf('Balance %d: account %s appears more than once.', $number, $account->code));
            }

            $seen[(int) $account->id] = true;

            if ($amount === 0) {
                continue;
            }

            $lines[] = new Line((int) $account->id, $side, $amount, 'Opening balance');
            $net += $side === Side::Debit ? $amount : -$amount;
        }

        if ($net !== 0) {
            if ($balancingAccountId === null) {
                throw new ValidationException(sprintf(
                    'The opening balances do not balance: %s exceed %s by %s; correct them or pass a balancing account.',
                    $net > 0 ? 'debits' : 'credits',
                    $net > 0 ? 'credits' : 'debits',
                    Money::format(abs($net)),
                ));
            }

            $balancing = $this->accounts->find($entityId, $balancingAccountId)
                ?? throw new ValidationException(sprintf('The balancing account %d does not exist in this entity.', $balancingAccountId));

            if ($balancing->type !== AccountType::Equity || ! $balancing->acceptsEntries || ! $balancing->active) {
                throw new ValidationException(sprintf('The balancing account %s must be an active equity account that accepts entries.', $balancing->code));
            }

            if (isset($seen[(int) $balancing->id])) {
                throw new ValidationException(sprintf('The balancing account %s already has a balance; leave it out of the list.', $balancing->code));
            }

            $lines[] = new Line((int) $balancing->id, $net > 0 ? Side::Credit : Side::Debit, abs($net), 'Opening balance difference');
        }

        if ($lines === []) {
            throw new ValidationException(sprintf('No opening balance to post for fiscal year %s.', $year->label));
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function account(int $entityId, array $row, int $number): Account
    {
        $id   = $row['account_id'] ?? null;
        $code = $row['account_code'] ?? null;

        if (is_int($id) || (is_string($id) && ctype_digit($id))) {
            $account = $this->accounts->find($entityId, (int) $id);
        } elseif (is_string($code) && trim($code) !== '') {
            $account = $this->accounts->findByCode($entityId, trim($code));
        } else {
            throw new ValidationException(sprintf('Balance %d: account_id or account_code is required.', $number));
        }

        if ($account === null) {
            throw new ValidationException(sprintf('Balance %d: the account does not exist in this entity.', $number));
        }

        if (! in_array($account->type, [AccountType::Asset, AccountType::Liability, AccountType::Equity], true)) {
            throw new ValidationException(sprintf('Balance %d: account %s is a %s account; opening balances go on asset, liability and equity accounts.', $number, $account->code, strtolower($account->type->value)));
        }

        return $account;
    }

    private function currentEntry(int $entityId, int $yearId): ?JournalEntry
    {
        $found = $this->entries->search(
            $entityId,
            new EntryFilter(EntryStatus::Posted, null, null, self::SOURCE, (string) $yearId),
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
