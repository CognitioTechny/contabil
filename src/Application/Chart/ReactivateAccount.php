<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Chart;

use CognitioTechny\Contabil\Domain\Chart\Account;
use CognitioTechny\Contabil\Domain\Chart\AccountRepositoryInterface;
use CognitioTechny\Contabil\Domain\Chart\ChartPolicy;
use CognitioTechny\Contabil\Domain\Exception\NotFoundException;

final class ReactivateAccount
{
    public function __construct(
        private readonly ChartPolicy $policy,
        private readonly AccountRepositoryInterface $accounts,
    ) {
    }

    public function execute(int $entityId, int $accountId): Account
    {
        $account = $this->accounts->find($entityId, $accountId)
            ?? throw new NotFoundException(sprintf('Account %d does not exist in this entity.', $accountId));

        if ($account->active) {
            return $account;
        }

        $this->policy->assertCanReactivate($account);

        return $this->accounts->save($account->withActive(true));
    }
}
