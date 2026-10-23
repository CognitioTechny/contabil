<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Domain\PostingRule;

interface PostingRuleRepositoryInterface
{
    public function insert(PostingRule $rule): PostingRule;

    public function find(int $entityId, int $id): ?PostingRule;

    /**
     * @return list<PostingRule> Every rule of the entity, by id.
     */
    public function all(int $entityId): array;

    /**
     * @return list<PostingRule> Active rules of one event, by id.
     */
    public function activeForEvent(int $entityId, string $event): array;

    public function save(PostingRule $rule): void;
}
