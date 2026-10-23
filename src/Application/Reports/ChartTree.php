<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Reports;

use CognitioTechny\Contabil\Domain\Chart\Account;

/**
 * The chart as a tree in memory, for the reports that roll leaf balances up
 * into their groups.
 */
final class ChartTree
{
    /**
     * @var array<int, Account>
     */
    private array $byId = [];

    /**
     * @var array<int, list<int>> Parent id (0 for roots) => child ids, by code.
     */
    private array $children = [];

    /**
     * @param list<Account> $accounts
     */
    public function __construct(array $accounts)
    {
        usort($accounts, static fn (Account $a, Account $b): int => strnatcmp($a->code, $b->code));

        foreach ($accounts as $account) {
            $this->byId[(int) $account->id] = $account;
        }

        foreach ($accounts as $account) {
            $parent = $account->parentId !== null && isset($this->byId[$account->parentId]) ? $account->parentId : 0;

            $this->children[$parent][] = (int) $account->id;
        }
    }

    public function get(int $id): ?Account
    {
        return $this->byId[$id] ?? null;
    }

    /**
     * @return list<Account> Accounts without a parent, by code.
     */
    public function roots(): array
    {
        return $this->accountsOf($this->children[0] ?? []);
    }

    /**
     * @return list<Account> Direct children, by code.
     */
    public function childrenOf(int $id): array
    {
        return $this->accountsOf($this->children[$id] ?? []);
    }

    /**
     * Ids of the accounts that take entries at or under an account.
     *
     * @return list<int>
     */
    public function leafIds(int $id): array
    {
        $account = $this->byId[$id] ?? null;

        if ($account === null) {
            return [];
        }

        $kids = $this->children[$id] ?? [];

        if ($kids === []) {
            return $account->acceptsEntries ? [$id] : [];
        }

        $ids = [];

        foreach ($kids as $kid) {
            $ids = [...$ids, ...$this->leafIds($kid)];
        }

        return $ids;
    }

    /**
     * Every account in tree order: a parent before its children, siblings by code.
     *
     * @return list<Account>
     */
    public function walk(): array
    {
        $ordered = [];
        $visit   = function (int $id) use (&$visit, &$ordered): void {
            $ordered[] = $this->byId[$id];

            foreach ($this->children[$id] ?? [] as $kid) {
                $visit($kid);
            }
        };

        foreach ($this->children[0] ?? [] as $root) {
            $visit($root);
        }

        return $ordered;
    }

    /**
     * @param list<int> $ids
     *
     * @return list<Account>
     */
    private function accountsOf(array $ids): array
    {
        return array_map(fn (int $id): Account => $this->byId[$id], $ids);
    }
}
