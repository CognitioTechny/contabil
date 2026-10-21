<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Chart;

use CognitioTechny\Contabil\Application\Repository\EntityGuard;
use CognitioTechny\Contabil\Application\Repository\TransactionRunnerInterface;
use CognitioTechny\Contabil\Domain\Chart\Account;
use CognitioTechny\Contabil\Domain\Chart\AccountRepositoryInterface;
use CognitioTechny\Contabil\Domain\Chart\AccountType;
use CognitioTechny\Contabil\Domain\Chart\ChartPolicy;

final class CreateAccount
{
    public function __construct(
        private readonly EntityGuard $entities,
        private readonly ChartPolicy $policy,
        private readonly AccountRepositoryInterface $accounts,
        private readonly TransactionRunnerInterface $transactions,
    ) {
    }

    /**
     * Creates an account. A null `$type` is inherited from the parent. A leaf
     * parent becomes a group, provided it has no movement.
     */
    public function execute(
        int $entityId,
        string $code,
        string $name,
        ?string $type = null,
        ?int $parentId = null,
        bool $contra = false,
        ?bool $acceptsEntries = null,
        bool $isCashAccount = false,
        ?string $referenceCode = null,
    ): Account {
        return $this->transactions->run(fn (): Account => $this->create(
            $entityId,
            $code,
            $name,
            $type,
            $parentId,
            $contra,
            $acceptsEntries,
            $isCashAccount,
            $referenceCode,
        ));
    }

    /**
     * Same as {@see self::execute()}, for callers that already run inside a
     * transaction. Every check happens before the first write.
     */
    public function create(
        int $entityId,
        string $code,
        string $name,
        ?string $type = null,
        ?int $parentId = null,
        bool $contra = false,
        ?bool $acceptsEntries = null,
        bool $isCashAccount = false,
        ?string $referenceCode = null,
    ): Account {
        $this->entities->assertExists($entityId);

        $account = $this->policy->newAccount(
            $entityId,
            $code,
            $name,
            $type === null ? null : AccountType::fromInput($type),
            $parentId,
            $contra,
            $acceptsEntries,
            $isCashAccount,
            $referenceCode,
        );

        if ($parentId !== null) {
            $parent = $this->accounts->find($entityId, $parentId);

            if ($parent !== null && $parent->acceptsEntries) {
                $this->accounts->save($parent->asGroup());
            }
        }

        return $this->accounts->save($account);
    }
}
