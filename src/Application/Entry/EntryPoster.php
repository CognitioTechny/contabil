<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Entry;

use CognitioTechny\Contabil\Application\Ports\ClockInterface;
use CognitioTechny\Contabil\Domain\Balance\BalanceRepositoryInterface;
use CognitioTechny\Contabil\Domain\Entry\EntryRepositoryInterface;
use CognitioTechny\Contabil\Domain\Entry\JournalEntry;
use CognitioTechny\Contabil\Domain\Entry\PostingPolicy;
use CognitioTechny\Contabil\Domain\Entry\Side;

/**
 * Moves a stored draft into the ledger: checks every rule, takes the next
 * number, seals the entry in the hash chain and updates the balance cache.
 * It runs inside the caller's transaction and never opens one itself.
 */
final class EntryPoster
{
    public function __construct(
        private readonly EntryRepositoryInterface $entries,
        private readonly PostingPolicy $policy,
        private readonly BalanceRepositoryInterface $balances,
        private readonly ClockInterface $clock,
    ) {
    }

    public function post(JournalEntry $draft): JournalEntry
    {
        $draft->assertPostable();
        $this->policy->assertAccounts($draft);

        [$year, $period] = $this->policy->openPeriodFor($draft->entityId, $draft->accrualDate);

        $this->entries->lockEntity($draft->entityId);

        $number                = $this->entries->nextNumber($draft->entityId, (int) $year->id);
        [$position, $previous] = $this->entries->nextChainLink($draft->entityId);

        $posted = $this->entries->savePosted($draft->posted((int) $year->id, $number, $position, $this->clock->now(), $previous));

        foreach ($this->movementByAccount($posted) as $accountId => [$debits, $credits]) {
            $this->balances->apply($accountId, (int) $period->id, $debits, $credits);
        }

        return $posted;
    }

    /**
     * @return array<int, array{0: int, 1: int}> Account id => [debits, credits].
     */
    private function movementByAccount(JournalEntry $entry): array
    {
        $sums = [];

        foreach ($entry->lines as $line) {
            $sums[$line->accountId] ??= [0, 0];
            $sums[$line->accountId][$line->side === Side::Debit ? 0 : 1] += $line->amount;
        }

        return $sums;
    }
}
