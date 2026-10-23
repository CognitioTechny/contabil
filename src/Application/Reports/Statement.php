<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Reports;

use CognitioTechny\Contabil\Domain\Money;

/**
 * Income Statement and Balance Sheet: sections that are trees of the chart.
 *
 * Every amount is the account's contribution to its section, positive when it
 * increases the section total. A contra account therefore shows negative
 * inside its parent. Debit-nature sections (assets, expenses) add debits minus
 * credits; credit-nature sections (liabilities, equity, revenue) add credits
 * minus debits.
 */
final class Statement implements Report
{
    /**
     * @param array<string, string>                     $summary
     * @param array<string, list<array<string, mixed>>> $sections Section name => account nodes with nested `children`.
     * @param array<string, mixed>                      $totals
     * @param array<string, mixed>                      $meta     Extra fields for the JSON form (period, basis, balanced).
     */
    public function __construct(
        private readonly string $slug,
        private readonly string $title,
        public readonly int $entityId,
        private readonly array $summary,
        public readonly array $sections,
        public readonly array $totals,
        private readonly array $meta,
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
        return $this->summary;
    }

    public function tableHeader(): array
    {
        return ['Section', 'Code', 'Account', 'Amount'];
    }

    public function tableRows(): array
    {
        $table = [];

        foreach ($this->sections as $name => $nodes) {
            $this->flatten($table, $name, $nodes, 0);
        }

        foreach ($this->totals as $label => $amount) {
            if (is_int($amount)) {
                $table[] = ['Total', '', ucfirst(str_replace('_', ' ', (string) $label)), Money::format($amount)];
            }
        }

        return $table;
    }

    public function toArray(): array
    {
        return ['entity_id' => $this->entityId] + $this->meta + ['sections' => $this->sections, 'totals' => $this->totals];
    }

    /**
     * @param list<list<string>>         $table
     * @param list<array<string, mixed>> $nodes
     */
    private function flatten(array &$table, string $section, array $nodes, int $depth): void
    {
        foreach ($nodes as $node) {
            $table[] = [ucfirst($section), $node['code'], str_repeat('  ', $depth) . $node['name'], Money::format($node['amount'])];

            $this->flatten($table, $section, $node['children'], $depth + 1);
        }
    }
}
