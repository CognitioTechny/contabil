<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Chart;

use CognitioTechny\Contabil\Domain\Chart\Account;
use CognitioTechny\Contabil\Domain\Chart\AccountRepositoryInterface;
use CognitioTechny\Contabil\Domain\Chart\ChartPolicy;
use CognitioTechny\Contabil\Domain\Exception\NotFoundException;

final class UpdateAccount
{
    public function __construct(
        private readonly ChartPolicy $policy,
        private readonly AccountRepositoryInterface $accounts,
    ) {
    }

    /**
     * @param array<string, mixed> $changes Any of code, name, type, contra,
     *                                      accepts_entries, is_cash_account, reference_code.
     */
    public function execute(int $entityId, int $accountId, array $changes): Account
    {
        $current = $this->accounts->find($entityId, $accountId)
            ?? throw new NotFoundException(sprintf('Account %d does not exist in this entity.', $accountId));

        return $this->accounts->save($this->policy->update($current, $changes));
    }
}
