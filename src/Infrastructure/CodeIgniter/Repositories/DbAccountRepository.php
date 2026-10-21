<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Infrastructure\CodeIgniter\Repositories;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CognitioTechny\Contabil\Application\Configuration\TableNames;
use CognitioTechny\Contabil\Application\Ports\ClockInterface;
use CognitioTechny\Contabil\Domain\Chart\Account;
use CognitioTechny\Contabil\Domain\Chart\AccountRepositoryInterface;
use CognitioTechny\Contabil\Domain\Chart\AccountType;
use CognitioTechny\Contabil\Domain\Chart\Nature;

final class DbAccountRepository implements AccountRepositoryInterface
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly TableNames $tables,
        private readonly ClockInterface $clock,
    ) {
    }

    public function find(int $entityId, int $id): ?Account
    {
        $row = $this->table()->where('entity_id', $entityId)->where('id', $id)->get()->getRowArray();

        return $row === null ? null : self::hydrate($row);
    }

    public function findByCode(int $entityId, string $code): ?Account
    {
        $row = $this->table()->where('entity_id', $entityId)->where('code', $code)->get()->getRowArray();

        return $row === null ? null : self::hydrate($row);
    }

    public function childrenOf(int $entityId, int $parentId): array
    {
        return self::sorted($this->table()->where('entity_id', $entityId)->where('parent_id', $parentId)->get()->getResultArray());
    }

    public function all(int $entityId): array
    {
        return self::sorted($this->table()->where('entity_id', $entityId)->get()->getResultArray());
    }

    public function save(Account $account): Account
    {
        $row = [
            'code'            => $account->code,
            'name'            => $account->name,
            'type'            => $account->type->value,
            'nature'          => $account->nature->value,
            'level'           => $account->level,
            'parent_id'       => $account->parentId,
            'accepts_entries' => (int) $account->acceptsEntries,
            'is_cash_account' => (int) $account->isCashAccount,
            'active'          => (int) $account->active,
            'reference_code'  => $account->referenceCode,
            'updated_at'      => $this->clock->now()->format('Y-m-d H:i:s'),
        ];

        if ($account->id === null) {
            $this->table()->insert($row + ['entity_id' => $account->entityId, 'created_at' => $row['updated_at']]);

            return $account->withId((int) $this->db->insertID());
        }

        $this->table()->where('entity_id', $account->entityId)->where('id', $account->id)->update($row);

        return $account;
    }

    public function delete(int $entityId, int $id): void
    {
        $this->table()->where('entity_id', $entityId)->where('id', $id)->delete();
    }

    private function table(): BaseBuilder
    {
        return $this->db->table($this->tables->name('accounts'));
    }

    /**
     * Natural order, so "1.2" comes before "1.10" on every database engine.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<Account>
     */
    private static function sorted(array $rows): array
    {
        $accounts = array_map(self::hydrate(...), $rows);
        usort($accounts, static fn (Account $a, Account $b): int => strnatcmp($a->code, $b->code));

        return $accounts;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): Account
    {
        return new Account(
            (int) $row['id'],
            (int) $row['entity_id'],
            (string) $row['code'],
            (string) $row['name'],
            AccountType::from((string) $row['type']),
            Nature::from((string) $row['nature']),
            (int) $row['level'],
            $row['parent_id'] === null ? null : (int) $row['parent_id'],
            (bool) $row['accepts_entries'],
            (bool) $row['is_cash_account'],
            (bool) $row['active'],
            $row['reference_code'] === null ? null : (string) $row['reference_code'],
        );
    }
}
