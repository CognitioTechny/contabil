<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Reports;

use CognitioTechny\Contabil\Domain\Money;
use DateTimeImmutable;

/**
 * Trial balance of the raw ledger: opening, debits, credits and closing per
 * account, with groups summing their children. It ignores the basis.
 */
final class TrialBalance implements Report
{
    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, int>         $totals
     * @param list<int>|null             $divergences Accounts whose cache differs from the ledger; null when the range does not end on a period end.
     */
    public function __construct(
        public readonly int $entityId,
        public readonly DateTimeImmutable $from,
        public readonly DateTimeImmutable $to,
        public readonly array $rows,
        public readonly array $totals,
        public readonly bool $balanced,
        public readonly ?array $divergences,
    ) {
    }

    public function slug(): string
    {
        return 'trial-balance';
    }

    public function title(): string
    {
        return 'Trial balance';
    }

    public function summary(): array
    {
        return [
            'Period'   => $this->from->format('Y-m-d') . ' to ' . $this->to->format('Y-m-d'),
            'Balanced' => $this->balanced ? 'yes' : 'NO',
            'Cache'    => $this->divergences === null ? 'not checked' : ($this->divergences === [] ? 'consistent' : count($this->divergences) . ' divergent accounts'),
        ];
    }

    public function tableHeader(): array
    {
        return ['Code', 'Account', 'Opening debit', 'Opening credit', 'Debits', 'Credits', 'Closing debit', 'Closing credit'];
    }

    public function tableRows(): array
    {
        $table = [];

        foreach ($this->rows as $row) {
            $table[] = [
                $row['code'], $row['name'],
                self::debit($row['opening']), self::credit($row['opening']),
                Money::format($row['debits']), Money::format($row['credits']),
                self::debit($row['closing']), self::credit($row['closing']),
            ];
        }

        $t = $this->totals;

        $table[] = [
            '', 'Total',
            Money::format($t['opening_debit']), Money::format($t['opening_credit']),
            Money::format($t['debits']), Money::format($t['credits']),
            Money::format($t['closing_debit']), Money::format($t['closing_credit']),
        ];

        return $table;
    }

    public function toArray(): array
    {
        return [
            'entity_id'   => $this->entityId,
            'from'        => $this->from->format('Y-m-d'),
            'to'          => $this->to->format('Y-m-d'),
            'balanced'    => $this->balanced,
            'divergences' => $this->divergences,
            'totals'      => $this->totals,
            'rows'        => $this->rows,
        ];
    }

    private static function debit(int $signed): string
    {
        return Money::format(max($signed, 0));
    }

    private static function credit(int $signed): string
    {
        return Money::format(max(-$signed, 0));
    }
}
