<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Domain\Chart;

/**
 * An account of the chart. Immutable: every change returns a new instance.
 */
final class Account
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $entityId,
        public readonly string $code,
        public readonly string $name,
        public readonly AccountType $type,
        public readonly Nature $nature,
        public readonly int $level,
        public readonly ?int $parentId,
        public readonly bool $acceptsEntries,
        public readonly bool $isCashAccount,
        public readonly bool $active,
        public readonly ?string $referenceCode = null,
    ) {
    }

    /**
     * A contra account has the opposite nature of its type (for example
     * accumulated depreciation under assets).
     */
    public function isContra(): bool
    {
        return $this->nature !== $this->type->defaultNature();
    }

    public function withId(int $id): self
    {
        return $this->with(['id' => $id]);
    }

    public function withCode(string $code): self
    {
        return $this->with(['code' => $code]);
    }

    public function withName(string $name): self
    {
        return $this->with(['name' => $name]);
    }

    public function withReferenceCode(?string $referenceCode): self
    {
        return $this->with(['referenceCode' => $referenceCode]);
    }

    public function withClassification(AccountType $type, bool $contra): self
    {
        return $this->with(['type' => $type, 'nature' => $type->natureFor($contra)]);
    }

    public function withParent(?int $parentId, int $level): self
    {
        return $this->with(['parentId' => $parentId, 'level' => $level]);
    }

    public function withLevel(int $level): self
    {
        return $this->with(['level' => $level]);
    }

    public function withCashFlag(bool $isCashAccount): self
    {
        return $this->with(['isCashAccount' => $isCashAccount]);
    }

    public function withActive(bool $active): self
    {
        return $this->with(['active' => $active]);
    }

    /**
     * Turns the account into a group: it stops accepting entries.
     */
    public function asGroup(): self
    {
        return $this->with(['acceptsEntries' => false, 'isCashAccount' => false]);
    }

    public function asLeaf(): self
    {
        return $this->with(['acceptsEntries' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'              => $this->id,
            'entity_id'       => $this->entityId,
            'code'            => $this->code,
            'name'            => $this->name,
            'type'            => $this->type->value,
            'nature'          => $this->nature->value,
            'contra'          => $this->isContra(),
            'level'           => $this->level,
            'parent_id'       => $this->parentId,
            'accepts_entries' => $this->acceptsEntries,
            'is_cash_account' => $this->isCashAccount,
            'active'          => $this->active,
            'reference_code'  => $this->referenceCode,
        ];
    }

    /**
     * @param array<string, mixed> $changes Property name => new value.
     */
    private function with(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}
