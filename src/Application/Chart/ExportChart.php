<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Chart;

use CognitioTechny\Contabil\Application\Repository\EntityGuard;

/**
 * Exports the chart in the layout {@see ImportChart} reads, parents before
 * children, so an export can be imported into an empty entity as it is.
 */
final class ExportChart
{
    public function __construct(
        private readonly EntityGuard $entities,
        private readonly GetAccountTree $tree,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(int $entityId, bool $includeInactive = true): array
    {
        $rows = [];
        $this->flatten($this->tree->execute($entityId, $includeInactive), null, $rows);

        return $rows;
    }

    public function execute(int $entityId, string $format = ChartFormat::CSV, bool $includeInactive = true): string
    {
        $this->entities->assertExists($entityId);

        return ChartFormat::render($this->rows($entityId, $includeInactive), $format);
    }

    /**
     * @param list<AccountNode>          $nodes
     * @param list<array<string, mixed>> $rows
     */
    private function flatten(array $nodes, ?string $parentCode, array &$rows): void
    {
        foreach ($nodes as $node) {
            $account = $node->account;

            $rows[] = [
                'code'            => $account->code,
                'name'            => $account->name,
                'type'            => $account->type->value,
                'parent_code'     => $parentCode,
                'contra'          => $account->isContra(),
                'accepts_entries' => $account->acceptsEntries,
                'is_cash_account' => $account->isCashAccount,
                'reference_code'  => $account->referenceCode,
            ];

            $this->flatten($node->children, $account->code, $rows);
        }
    }
}
