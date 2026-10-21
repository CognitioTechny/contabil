<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Chart;

use CognitioTechny\Contabil\Domain\Chart\Account;
use CognitioTechny\Contabil\Domain\Chart\AccountRepositoryInterface;
use CognitioTechny\Contabil\Domain\Exception\NotFoundException;

final class GetAccount
{
    public function __construct(private readonly AccountRepositoryInterface $accounts)
    {
    }

    public function execute(int $entityId, int $accountId): Account
    {
        return $this->accounts->find($entityId, $accountId)
            ?? throw new NotFoundException(sprintf('Account %d does not exist in this entity.', $accountId));
    }
}
