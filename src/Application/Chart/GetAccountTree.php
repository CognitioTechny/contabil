<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Chart;

use CognitioTechny\Contabil\Application\Repository\EntityGuard;
use CognitioTechny\Contabil\Domain\Chart\Account;
use CognitioTechny\Contabil\Domain\Chart\AccountRepositoryInterface;

final class GetAccountTree
{
    public function __construct(
        private readonly EntityGuard $entities,
        private readonly AccountRepositoryInterface $accounts,
    ) {
    }

    /**
     * @return list<AccountNode> The root accounts, each with its subtree. An
     *                           inactive account hides its (inactive) subtree
     *                           unless `$includeInactive` is set.
     */
    public function execute(int $entityId, bool $includeInactive = false): array
    {
        $this->entities->assertExists($entityId);

        $byParent = [];

        foreach ($this->accounts->all($entityId) as $account) {
            if ($includeInactive || $account->active) {
                $byParent[$account->parentId ?? 0][] = $account;
            }
        }

        return $this->branch($byParent, 0);
    }

    /**
     * @param array<int, list<Account>> $byParent
     *
     * @return list<AccountNode>
     */
    private function branch(array $byParent, int $parentId): array
    {
        $nodes = [];

        foreach ($byParent[$parentId] ?? [] as $account) {
            $nodes[] = new AccountNode($account, $this->branch($byParent, (int) $account->id));
        }

        return $nodes;
    }
}
