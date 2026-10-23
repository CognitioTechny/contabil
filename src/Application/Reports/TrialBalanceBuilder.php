<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Reports;

use CognitioTechny\Contabil\Application\Repository\EntityGuard;
use CognitioTechny\Contabil\Domain\Balance\BalanceRepositoryInterface;
use CognitioTechny\Contabil\Domain\Chart\AccountRepositoryInterface;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;
use DateTimeImmutable;

/**
 * Builds the {@see TrialBalance}. Balances are signed with debit positive.
 */
final class TrialBalanceBuilder
{
    public function __construct(
        private readonly EntityGuard $entities,
        private readonly AccountRepositoryInterface $accounts,
        private readonly ReportReaderInterface $reader,
        private readonly BalanceRepositoryInterface $balances,
    ) {
    }

    /**
     * @param bool $includeZero Also list accounts with no balance and no movement.
     */
    public function execute(int $entityId, DateTimeImmutable $from, DateTimeImmutable $to, bool $includeZero = false): TrialBalance
    {
        $this->entities->assertExists($entityId);

        if ($from > $to) {
            throw new ValidationException('The start date must not be after the end date.');
        }

        $tree     = new ChartTree($this->accounts->all($entityId));
        $opening  = $this->reader->accrualSums($entityId, null, $from->modify('-1 day'));
        $movement = $this->reader->accrualSums($entityId, $from, $to);

        // Leaf figures first, then every group adds up its children.
        $figures = [];

        foreach ($tree->walk() as $account) {
            $id = (int) $account->id;

            if (! $account->acceptsEntries) {
                continue;
            }

            [$od, $oc] = $opening[$id] ?? [0, 0];
            [$d, $c]   = $movement[$id] ?? [0, 0];

            $figures[$id] = ['opening' => $od - $oc, 'debits' => $d, 'credits' => $c];
        }

        $rows   = [];
        $totals = ['opening_debit' => 0, 'opening_credit' => 0, 'debits' => 0, 'credits' => 0, 'closing_debit' => 0, 'closing_credit' => 0];

        foreach ($tree->walk() as $account) {
            $id   = (int) $account->id;
            $sum  = ['opening' => 0, 'debits' => 0, 'credits' => 0];
            $leaf = $tree->leafIds($id);

            foreach ($leaf as $leafId) {
                foreach ($sum as $key => $_) {
                    $sum[$key] += $figures[$leafId][$key] ?? 0;
                }
            }

            $closing = $sum['opening'] + $sum['debits'] - $sum['credits'];

            if (! $includeZero && $sum['opening'] === 0 && $sum['debits'] === 0 && $sum['credits'] === 0 && $closing === 0) {
                continue;
            }

            $rows[] = [
                'account_id' => $id,
                'code'       => $account->code,
                'name'       => $account->name,
                'level'      => $account->level,
                'is_group'   => ! $account->acceptsEntries,
                'type'       => $account->type->value,
                'nature'     => $account->nature->value,
                'opening'    => $sum['opening'],
                'debits'     => $sum['debits'],
                'credits'    => $sum['credits'],
                'closing'    => $closing,
            ];

            if ($account->acceptsEntries) {
                $totals['opening_debit'] += max($sum['opening'], 0);
                $totals['opening_credit'] += max(-$sum['opening'], 0);
                $totals['debits'] += $sum['debits'];
                $totals['credits'] += $sum['credits'];
                $totals['closing_debit'] += max($closing, 0);
                $totals['closing_credit'] += max(-$closing, 0);
            }
        }

        $balanced = $totals['debits'] === $totals['credits']
            && $totals['opening_debit'] === $totals['opening_credit']
            && $totals['closing_debit'] === $totals['closing_credit'];

        return new TrialBalance($entityId, $from, $to, $rows, $totals, $balanced, $this->divergences($entityId, $to, $tree, $opening, $movement));
    }

    /**
     * Compares the balance cache with the ledger at the end of the range.
     *
     * @param array<int, array{0: int, 1: int}> $opening
     * @param array<int, array{0: int, 1: int}> $movement
     *
     * @return list<int>|null
     */
    private function divergences(int $entityId, DateTimeImmutable $to, ChartTree $tree, array $opening, array $movement): ?array
    {
        $cached = $this->balances->closingAsOf($entityId, $to);

        if ($cached === null) {
            return null;
        }

        $divergent = [];

        foreach ($tree->walk() as $account) {
            $id = (int) $account->id;

            if (! $account->acceptsEntries) {
                continue;
            }

            [$od, $oc] = $opening[$id] ?? [0, 0];
            [$d, $c]   = $movement[$id] ?? [0, 0];

            if (($cached[$id] ?? 0) !== $od - $oc + $d - $c) {
                $divergent[] = $id;
            }
        }

        return $divergent;
    }
}
