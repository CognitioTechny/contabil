<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Domain\PostingRule;

use stdClass;

/**
 * Maps a host event to the pair of accounts its entry uses: debit one, credit
 * the other. The optional condition is a set of attribute values that must all
 * be equal for the rule to apply, for example `{"kind": "fee"}`.
 */
final class PostingRule
{
    /**
     * @param array<string, scalar> $condition
     */
    public function __construct(
        public readonly ?int $id,
        public readonly int $entityId,
        public readonly string $event,
        public readonly array $condition,
        public readonly int $debitAccountId,
        public readonly int $creditAccountId,
        public readonly bool $active = true,
    ) {
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function matches(array $attributes): bool
    {
        foreach ($this->condition as $key => $expected) {
            if (! array_key_exists($key, $attributes) || (string) $attributes[$key] !== (string) $expected) {
                return false;
            }
        }

        return true;
    }

    public function withId(int $id): self
    {
        return new self($id, $this->entityId, $this->event, $this->condition, $this->debitAccountId, $this->creditAccountId, $this->active);
    }

    public function withActive(bool $active): self
    {
        return new self($this->id, $this->entityId, $this->event, $this->condition, $this->debitAccountId, $this->creditAccountId, $active);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'                => $this->id,
            'entity_id'         => $this->entityId,
            'event'             => $this->event,
            'condition'         => $this->condition === [] ? new stdClass() : $this->condition,
            'debit_account_id'  => $this->debitAccountId,
            'credit_account_id' => $this->creditAccountId,
            'active'            => $this->active,
        ];
    }
}
