<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Ledger;

use CognitioTechny\Contabil\Application\Repository\EntityGuard;
use CognitioTechny\Contabil\Application\Repository\TransactionRunnerInterface;
use CognitioTechny\Contabil\Domain\Balance\BalanceRepositoryInterface;
use CognitioTechny\Contabil\Domain\Entry\EntryRepositoryInterface;

/**
 * Rebuilds the balance cache from the entry lines, which stay the source of truth.
 */
final class RecalculateBalances
{
    public function __construct(
        private readonly EntityGuard $entities,
        private readonly BalanceRepositoryInterface $balances,
        private readonly EntryRepositoryInterface $entries,
        private readonly TransactionRunnerInterface $transactions,
    ) {
    }

    /**
     * @return int Number of balance rows written.
     */
    public function execute(int $entityId): int
    {
        $this->entities->assertExists($entityId);

        return $this->transactions->run(function () use ($entityId): int {
            // No posting may run while the cache is rebuilt.
            $this->entries->lockEntity($entityId);

            return $this->balances->rebuild($entityId);
        });
    }
}
