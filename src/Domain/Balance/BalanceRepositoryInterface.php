<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Domain\Balance;

use DateTimeImmutable;

/**
 * The balance cache, per account and period. Balances are signed with debit
 * positive. The entry lines remain the source of truth; the cache can always
 * be rebuilt.
 */
interface BalanceRepositoryInterface
{
    /**
     * Adds a movement to the cache inside the posting transaction: the period
     * row grows and the later rows of the account shift by the net amount.
     */
    public function apply(int $accountId, int $periodId, int $debits, int $credits): void;

    /**
     * Drops and recomputes every balance of the entity from the entry lines.
     *
     * @return int Rows written.
     */
    public function rebuild(int $entityId): int;

    /**
     * Closing balance of every account that has a cache row on or before the
     * date, taken from the latest such row. Null when the date is not the last
     * day of a period, since the cache cannot answer for a partial period.
     *
     * @return array<int, int>|null Account id => signed closing balance.
     */
    public function closingAsOf(int $entityId, DateTimeImmutable $date): ?array;
}
