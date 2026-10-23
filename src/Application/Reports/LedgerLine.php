<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Reports;

use CognitioTechny\Contabil\Domain\Entry\Side;
use DateTimeImmutable;

/**
 * One line of the ledger with the data of its entry, as read for reports.
 */
final class LedgerLine
{
    public function __construct(
        public readonly int $lineId,
        public readonly int $entryId,
        public readonly int $entryNumber,
        public readonly DateTimeImmutable $accrualDate,
        public readonly string $description,
        public readonly ?string $lineDescription,
        public readonly int $accountId,
        public readonly Side $side,
        public readonly int $amount,
        public readonly ?string $sourceType = null,
        public readonly ?string $sourceId = null,
    ) {
    }

    /**
     * Amount with debit positive and credit negative.
     */
    public function signed(): int
    {
        return $this->side === Side::Debit ? $this->amount : -$this->amount;
    }

    /**
     * @return array{0: string, 1: int, 2: int}
     */
    public function cursor(): array
    {
        return [$this->accrualDate->format('Y-m-d'), $this->entryId, $this->lineId];
    }
}
