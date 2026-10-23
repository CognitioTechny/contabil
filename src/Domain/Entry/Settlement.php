<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Domain\Entry;

use DateTimeImmutable;

/**
 * Link saying that a settlement entry (a payment or a collection) settles part
 * of an earlier entry. The cash basis recognizes revenue and expense through
 * these links.
 */
final class Settlement
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $settlementEntryId,
        public readonly int $settledEntryId,
        public readonly int $amount,
        public readonly DateTimeImmutable $date,
    ) {
    }

    public function withId(int $id): self
    {
        return new self($id, $this->settlementEntryId, $this->settledEntryId, $this->amount, $this->date);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'                  => $this->id,
            'settlement_entry_id' => $this->settlementEntryId,
            'settled_entry_id'    => $this->settledEntryId,
            'amount'              => $this->amount,
            'date'                => $this->date->format('Y-m-d'),
        ];
    }
}
