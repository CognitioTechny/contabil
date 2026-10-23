<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Reports;

use CognitioTechny\Contabil\Domain\Money;
use DateTimeImmutable;

/**
 * General ledger of the raw ledger: per account, an opening balance, the
 * movements with a running balance, and the closing balance. Balances are in
 * the account's own nature, so a positive number is on its normal side.
 */
final class GeneralLedger implements Report
{
    /**
     * @param list<array<string, mixed>> $sections One per account: `account`, `opening`, `rows`, `debits`, `credits`, `closing`.
     */
    public function __construct(
        public readonly int $entityId,
        public readonly ?DateTimeImmutable $from,
        public readonly ?DateTimeImmutable $to,
        public readonly array $sections,
        public readonly ?string $nextCursor,
    ) {
    }

    public function slug(): string
    {
        return 'general-ledger';
    }

    public function title(): string
    {
        return 'General ledger';
    }

    public function summary(): array
    {
        return [
            'Period'   => ($this->from?->format('Y-m-d') ?? 'beginning') . ' to ' . ($this->to?->format('Y-m-d') ?? 'end'),
            'Accounts' => (string) count($this->sections),
        ];
    }

    public function tableHeader(): array
    {
        return ['Account', 'Date', 'Entry', 'Description', 'Debit', 'Credit', 'Balance'];
    }

    public function tableRows(): array
    {
        $table = [];

        foreach ($this->sections as $section) {
            $label   = $section['account']['code'] . ' ' . $section['account']['name'];
            $table[] = [$label, '', '', 'Opening balance', '', '', Money::format($section['opening'])];

            foreach ($section['rows'] as $row) {
                $table[] = [
                    $label, $row['date'], (string) $row['entry_number'], $row['description'],
                    $row['debit'] === 0 ? '' : Money::format($row['debit']),
                    $row['credit'] === 0 ? '' : Money::format($row['credit']),
                    Money::format($row['balance']),
                ];
            }

            $table[] = [$label, '', '', 'Closing balance', Money::format($section['debits']), Money::format($section['credits']), Money::format($section['closing'])];
        }

        return $table;
    }

    public function toArray(): array
    {
        return [
            'entity_id'   => $this->entityId,
            'from'        => $this->from?->format('Y-m-d'),
            'to'          => $this->to?->format('Y-m-d'),
            'sections'    => $this->sections,
            'next_cursor' => $this->nextCursor,
        ];
    }
}
