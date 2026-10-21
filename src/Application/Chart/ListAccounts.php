<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Chart;

use CognitioTechny\Contabil\Application\Repository\EntityGuard;
use CognitioTechny\Contabil\Domain\Chart\Account;
use CognitioTechny\Contabil\Domain\Chart\AccountRepositoryInterface;

final class ListAccounts
{
    public function __construct(
        private readonly EntityGuard $entities,
        private readonly AccountRepositoryInterface $accounts,
    ) {
    }

    /**
     * @return list<Account> Ordered by code.
     */
    public function execute(int $entityId, bool $includeInactive = false): array
    {
        $this->entities->assertExists($entityId);

        $all = $this->accounts->all($entityId);

        return $includeInactive
            ? $all
            : array_values(array_filter($all, static fn (Account $account): bool => $account->active));
    }
}
