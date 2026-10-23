<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Reports;

use CognitioTechny\Contabil\Domain\Money;

/**
 * Entries with their lines: the Journal (by date) and the statement by source
 * share this shape, only the title and the selection differ.
 */
final class EntryListing implements Report
{
    /**
     * @param list<array<string, mixed>> $entries Each entry as an array, with `lines` carrying `account_code` and `account_name`.
     * @param array<string, string>      $summary
     */
    public function __construct(
        private readonly string $slug,
        private readonly string $title,
        public readonly int $entityId,
        public readonly array $entries,
        private readonly array $summary,
        public readonly ?string $nextCursor = null,
        public readonly int $totalDebits = 0,
    ) {
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function summary(): array
    {
        return $this->summary + ['Entries' => (string) count($this->entries), 'Total' => Money::format($this->totalDebits)];
    }

    public function tableHeader(): array
    {
        return ['Number', 'Date', 'Status', 'Description', 'Account', 'Debit', 'Credit'];
    }

    public function tableRows(): array
    {
        $table = [];

        foreach ($this->entries as $entry) {
            foreach ($entry['lines'] as $index => $line) {
                $table[] = [
                    (string) $entry['number'],
                    $entry['accrual_date'],
                    $entry['status'],
                    $index === 0 ? $entry['description'] : '',
                    $line['account_code'] . ' ' . $line['account_name'],
                    $line['side'] === 'D' ? Money::format($line['amount']) : '',
                    $line['side'] === 'C' ? Money::format($line['amount']) : '',
                ];
            }
        }

        return $table;
    }

    public function toArray(): array
    {
        return [
            'entity_id'   => $this->entityId,
            'total'       => $this->totalDebits,
            'entries'     => $this->entries,
            'next_cursor' => $this->nextCursor,
        ];
    }
}
