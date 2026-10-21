<?php

declare(strict_types=1);

namespace Tests\Support;

use CognitioTechny\Contabil\Application\Repository\EntityRepositoryInterface;
use CognitioTechny\Contabil\Application\Repository\TransactionRunnerInterface;
use CognitioTechny\Contabil\Domain\Chart\Account;
use CognitioTechny\Contabil\Domain\Chart\AccountMovementInterface;
use CognitioTechny\Contabil\Domain\Chart\AccountRepositoryInterface;
use Throwable;

/**
 * Database-free stand-in for the chart storage, the movement check, the entity
 * list and the transaction runner. A failed transaction restores the chart.
 */
final class InMemoryChart implements AccountRepositoryInterface, AccountMovementInterface, EntityRepositoryInterface, TransactionRunnerInterface
{
    /** @var array<int, Account> */
    private array $accounts = [];

    /** @var array<int, true> */
    private array $entities = [1 => true];

    /** @var array<int, true> */
    private array $withMovement = [];
    private int $nextId         = 1;

    public function markMovement(int $accountId): void
    {
        $this->withMovement[$accountId] = true;
    }

    public function find(int $entityId, int $id): ?Account
    {
        $account = $this->accounts[$id] ?? null;

        return $account !== null && $account->entityId === $entityId ? $account : null;
    }

    public function findByCode(int $entityId, string $code): ?Account
    {
        foreach ($this->accounts as $account) {
            if ($account->entityId === $entityId && $account->code === $code) {
                return $account;
            }
        }

        return null;
    }

    public function childrenOf(int $entityId, int $parentId): array
    {
        return array_values(array_filter(
            $this->all($entityId),
            static fn (Account $account): bool => $account->parentId === $parentId,
        ));
    }

    public function all(int $entityId): array
    {
        $accounts = array_values(array_filter($this->accounts, static fn (Account $account): bool => $account->entityId === $entityId));
        usort($accounts, static fn (Account $a, Account $b): int => strnatcmp($a->code, $b->code));

        return $accounts;
    }

    public function save(Account $account): Account
    {
        if ($account->id === null) {
            $account = $account->withId($this->nextId++);
        }

        $this->accounts[$account->id] = $account;

        return $account;
    }

    public function delete(int $entityId, int $id): void
    {
        unset($this->accounts[$id]);
    }

    public function hasMovement(int $accountId): bool
    {
        return isset($this->withMovement[$accountId]);
    }

    public function exists(int $id): bool
    {
        return isset($this->entities[$id]);
    }

    public function create(string $name, ?string $taxId, string $currency, string $basis): int
    {
        $id                  = max(array_keys($this->entities)) + 1;
        $this->entities[$id] = true;

        return $id;
    }

    public function run(callable $work): mixed
    {
        $snapshot = [$this->accounts, $this->nextId];

        try {
            return $work();
        } catch (Throwable $e) {
            [$this->accounts, $this->nextId] = $snapshot;

            throw $e;
        }
    }
}
