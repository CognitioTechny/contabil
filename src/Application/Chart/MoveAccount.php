<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Chart;

use CognitioTechny\Contabil\Application\Repository\TransactionRunnerInterface;
use CognitioTechny\Contabil\Domain\Chart\Account;
use CognitioTechny\Contabil\Domain\Chart\AccountRepositoryInterface;
use CognitioTechny\Contabil\Domain\Chart\ChartPolicy;
use CognitioTechny\Contabil\Domain\Exception\NotFoundException;

final class MoveAccount
{
    public function __construct(
        private readonly ChartPolicy $policy,
        private readonly AccountRepositoryInterface $accounts,
        private readonly TransactionRunnerInterface $transactions,
    ) {
    }

    /**
     * Moves an account, with its subtree, under another parent (null for the
     * root). The levels of the subtree follow. With a code mask, pass the code
     * the account should have at its new level.
     */
    public function execute(int $entityId, int $accountId, ?int $newParentId, ?string $newCode = null): Account
    {
        $account = $this->accounts->find($entityId, $accountId)
            ?? throw new NotFoundException(sprintf('Account %d does not exist in this entity.', $accountId));

        return $this->transactions->run(function () use ($account, $newParentId, $newCode): Account {
            $plan = $this->policy->planMove($account, $newParentId, $newCode);

            if ($plan['moved'] === []) {
                return $account;
            }

            if ($plan['parent'] !== null) {
                $this->accounts->save($plan['parent']);
            }

            $result = $account;

            foreach ($plan['moved'] as $moved) {
                $saved = $this->accounts->save($moved);
                $result = $saved->id === $account->id ? $saved : $result;
            }

            return $result;
        });
    }
}
