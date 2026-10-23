<?php

declare(strict_types=1);

namespace Tests\Support;

use CognitioTechny\Contabil\Domain\PostingRule\PostingRule;
use CognitioTechny\Contabil\Domain\PostingRule\PostingRuleRepositoryInterface;

final class InMemoryPostingRules implements PostingRuleRepositoryInterface
{
    /**
     * @var array<int, PostingRule>
     */
    private array $rules = [];

    private int $nextId = 1;

    public function insert(PostingRule $rule): PostingRule
    {
        return $this->rules[$this->nextId] = $rule->withId($this->nextId++);
    }

    public function find(int $entityId, int $id): ?PostingRule
    {
        $rule = $this->rules[$id] ?? null;

        return $rule !== null && $rule->entityId === $entityId ? $rule : null;
    }

    public function all(int $entityId): array
    {
        return array_values(array_filter($this->rules, static fn (PostingRule $r): bool => $r->entityId === $entityId));
    }

    public function activeForEvent(int $entityId, string $event): array
    {
        return array_values(array_filter($this->all($entityId), static fn (PostingRule $r): bool => $r->active && $r->event === $event));
    }

    public function save(PostingRule $rule): void
    {
        $this->rules[$rule->id] = $rule;
    }
}
