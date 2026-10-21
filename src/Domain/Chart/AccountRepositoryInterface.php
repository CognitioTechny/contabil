<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Domain\Chart;

/**
 * Storage of the chart. Every lookup is scoped to an entity, so an account of
 * another entity is simply not found.
 */
interface AccountRepositoryInterface
{
    public function find(int $entityId, int $id): ?Account;

    public function findByCode(int $entityId, string $code): ?Account;

    /**
     * @return list<Account> Direct children, ordered by code.
     */
    public function childrenOf(int $entityId, int $parentId): array;

    /**
     * @return list<Account> The whole chart of the entity, ordered by code.
     */
    public function all(int $entityId): array;

    /**
     * Inserts the account when it has no id, updates it otherwise.
     *
     * @return Account The stored account, with its id.
     */
    public function save(Account $account): Account;

    public function delete(int $entityId, int $id): void;
}
