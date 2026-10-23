<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Domain\Entry;

use CognitioTechny\Contabil\Domain\Exception\ValidationException;

/**
 * One debit or credit of a journal entry. The amount is in minor units and
 * always positive; the sign comes from the side.
 */
final class Line
{
    public function __construct(
        public readonly int $accountId,
        public readonly Side $side,
        public readonly int $amount,
        public readonly ?string $description = null,
        public readonly int $position = 1,
        public readonly ?int $id = null,
    ) {
        if ($accountId < 1) {
            throw new ValidationException('A line needs an account.');
        }

        if ($amount < 1) {
            throw new ValidationException('The amount of a line must be a positive integer in minor units.');
        }

        if ($description !== null && mb_strlen($description) > 500) {
            throw new ValidationException('The description of a line has at most 500 characters.');
        }
    }

    public function withPosition(int $position): self
    {
        return new self($this->accountId, $this->side, $this->amount, $this->description, $position, $this->id);
    }

    public function inverted(): self
    {
        return new self($this->accountId, $this->side->opposite(), $this->amount, $this->description, $this->position);
    }

    /**
     * Amount with debit positive and credit negative.
     */
    public function signed(): int
    {
        return $this->side === Side::Debit ? $this->amount : -$this->amount;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'account_id'  => $this->accountId,
            'side'        => $this->side->value,
            'amount'      => $this->amount,
            'description' => $this->description,
            'position'    => $this->position,
        ];
    }
}
