<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Infrastructure\CodeIgniter\Repositories;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CognitioTechny\Contabil\Application\Configuration\TableNames;
use CognitioTechny\Contabil\Application\Ports\ClockInterface;
use CognitioTechny\Contabil\Domain\PostingRule\PostingRule;
use CognitioTechny\Contabil\Domain\PostingRule\PostingRuleRepositoryInterface;

final class DbPostingRuleRepository implements PostingRuleRepositoryInterface
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly TableNames $tables,
        private readonly ClockInterface $clock,
    ) {
    }

    public function insert(PostingRule $rule): PostingRule
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $this->rules()->insert([
            'entity_id'         => $rule->entityId,
            'event'             => $rule->event,
            'condition'         => $rule->condition === [] ? null : json_encode($rule->condition, JSON_THROW_ON_ERROR),
            'debit_account_id'  => $rule->debitAccountId,
            'credit_account_id' => $rule->creditAccountId,
            'active'            => $rule->active ? 1 : 0,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        return $rule->withId((int) $this->db->insertID());
    }

    public function find(int $entityId, int $id): ?PostingRule
    {
        $row = $this->rules()->where('entity_id', $entityId)->where('id', $id)->get()->getRowArray();

        return $row === null ? null : self::hydrate($row);
    }

    public function all(int $entityId): array
    {
        return array_map(self::hydrate(...), $this->rules()->where('entity_id', $entityId)->orderBy('id', 'ASC')->get()->getResultArray());
    }

    public function activeForEvent(int $entityId, string $event): array
    {
        return array_map(self::hydrate(...), $this->rules()
            ->where('entity_id', $entityId)
            ->where('event', $event)
            ->where('active', 1)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray());
    }

    public function save(PostingRule $rule): void
    {
        $this->rules()->where('id', $rule->id)->update([
            'active'     => $rule->active ? 1 : 0,
            'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);
    }

    private function rules(): BaseBuilder
    {
        return $this->db->table($this->tables->name('posting_rules'));
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): PostingRule
    {
        $condition = $row['condition'] === null ? [] : json_decode((string) $row['condition'], true, 8, JSON_THROW_ON_ERROR);

        return new PostingRule(
            (int) $row['id'],
            (int) $row['entity_id'],
            (string) $row['event'],
            is_array($condition) ? $condition : [],
            (int) $row['debit_account_id'],
            (int) $row['credit_account_id'],
            (int) $row['active'] === 1,
        );
    }
}
