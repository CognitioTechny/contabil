<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Chart;

use CognitioTechny\Contabil\Domain\Chart\AccountRepositoryInterface;
use CognitioTechny\Contabil\Domain\Chart\ChartPolicy;
use CognitioTechny\Contabil\Domain\Exception\NotFoundException;

/**
 * Removes an account that was created by mistake: no children, no movement.
 */
final class DeleteAccount
{
    public function __construct(
        private readonly ChartPolicy $policy,
        private readonly AccountRepositoryInterface $accounts,
    ) {
    }

    public function execute(int $entityId, int $accountId): void
    {
        $account = $this->accounts->find($entityId, $accountId)
            ?? throw new NotFoundException(sprintf('Account %d does not exist in this entity.', $accountId));

        $this->policy->assertCanDelete($account);
        $this->accounts->delete($entityId, $accountId);
    }
}
