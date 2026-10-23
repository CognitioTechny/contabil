<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Reports;

use CognitioTechny\Contabil\Application\Repository\EntityGuard;
use CognitioTechny\Contabil\Application\Repository\EntityRepositoryInterface;
use CognitioTechny\Contabil\Domain\Chart\Account;
use CognitioTechny\Contabil\Domain\Chart\AccountRepositoryInterface;
use CognitioTechny\Contabil\Domain\Chart\AccountType;
use CognitioTechny\Contabil\Domain\Chart\Nature;
use CognitioTechny\Contabil\Domain\Exception\UnsupportedOperationException;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;
use CognitioTechny\Contabil\Domain\Reporting\Basis;
use DateTimeImmutable;

/**
 * Builds the Income Statement and the Balance Sheet from the chart. Their
 * structure is the chart itself: no account, section or coefficient is
 * hard-coded, so any chart a host defines produces a valid statement.
 */
final class StatementBuilder
{
    public function __construct(
        private readonly EntityGuard $guard,
        private readonly EntityRepositoryInterface $entities,
        private readonly AccountRepositoryInterface $accounts,
        private readonly ReportReaderInterface $reader,
        private readonly AccountMovements $movements,
    ) {
    }

    /**
     * Revenue less expense over a period. Revenue and expense follow the basis;
     * `null` takes the entity's default.
     */
    public function incomeStatement(int $entityId, DateTimeImmutable $from, DateTimeImmutable $to, ?string $basis = null, bool $includeZero = false): Statement
    {
        $this->guard->assertExists($entityId);

        if ($from > $to) {
            throw new ValidationException('The start date must not be after the end date.');
        }

        $resolved = Basis::resolve($basis, $this->entities->defaultBasis($entityId) ?? 'ACCRUAL');
        $tree     = new ChartTree($this->accounts->all($entityId));
        $sums     = $this->movements->for($entityId, $from, $to, $resolved);

        $revenue  = $this->section($tree, AccountType::Revenue, $sums, $includeZero);
        $expenses = $this->section($tree, AccountType::Expense, $sums, $includeZero);

        $totalRevenue  = self::sum($revenue);
        $totalExpenses = self::sum($expenses);

        return new Statement(
            'income-statement',
            'Income statement',
            $entityId,
            [
                'Period' => $from->format('Y-m-d') . ' to ' . $to->format('Y-m-d'),
                'Basis'  => $resolved->value,
            ],
            ['revenue' => $revenue, 'expenses' => $expenses],
            ['revenue' => $totalRevenue, 'expenses' => $totalExpenses, 'net_result' => $totalRevenue - $totalExpenses],
            ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'basis' => $resolved->value],
        );
    }

    /**
     * Assets, liabilities and equity at the end of a day, always on the accrual
     * basis. Revenue and expense not yet closed to equity appear as
     * `current_result`, so assets always equal liabilities plus equity.
     *
     * @throws UnsupportedOperationException The cash basis was requested.
     */
    public function balanceSheet(int $entityId, DateTimeImmutable $asOf, ?string $basis = null, bool $includeZero = false): Statement
    {
        $this->guard->assertExists($entityId);

        if ($basis !== null && $basis !== '' && Basis::resolve($basis, 'ACCRUAL') === Basis::Cash) {
            throw new UnsupportedOperationException('The balance sheet supports the accrual basis only in this version.');
        }

        $tree = new ChartTree($this->accounts->all($entityId));
        $sums = $this->reader->accrualSums($entityId, null, $asOf);

        $assets      = $this->section($tree, AccountType::Asset, $sums, $includeZero);
        $liabilities = $this->section($tree, AccountType::Liability, $sums, $includeZero);
        $equity      = $this->section($tree, AccountType::Equity, $sums, $includeZero);

        $result = self::sum($this->section($tree, AccountType::Revenue, $sums, true))
            - self::sum($this->section($tree, AccountType::Expense, $sums, true));

        $totalAssets    = self::sum($assets);
        $liabilityTotal = self::sum($liabilities);
        $equityTotal    = self::sum($equity) + $result;
        $difference     = $totalAssets - $liabilityTotal - $equityTotal;

        return new Statement(
            'balance-sheet',
            'Balance sheet',
            $entityId,
            ['As of' => $asOf->format('Y-m-d'), 'Basis' => Basis::Accrual->value, 'Balanced' => $difference === 0 ? 'yes' : 'NO'],
            ['assets' => $assets, 'liabilities' => $liabilities, 'equity' => $equity],
            [
                'assets'                 => $totalAssets,
                'liabilities'            => $liabilityTotal,
                'equity'                 => self::sum($equity),
                'current_result'         => $result,
                'liabilities_and_equity' => $liabilityTotal + $equityTotal,
                'difference'             => $difference,
            ],
            ['as_of' => $asOf->format('Y-m-d'), 'basis' => Basis::Accrual->value, 'balanced' => $difference === 0],
        );
    }

    /**
     * Roots of one account type with their subtrees. Zero-valued branches are
     * dropped unless asked for.
     *
     * @param array<int, array{0: int, 1: int}> $sums
     *
     * @return list<array<string, mixed>>
     */
    private function section(ChartTree $tree, AccountType $type, array $sums, bool $includeZero): array
    {
        $nodes = [];

        foreach ($tree->roots() as $root) {
            if ($root->type !== $type) {
                continue;
            }

            $node = $this->node($tree, $root, $sums, $includeZero);

            if ($node !== null) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * @param array<int, array{0: int, 1: int}> $sums
     *
     * @return array<string, mixed>|null
     */
    private function node(ChartTree $tree, Account $account, array $sums, bool $includeZero): ?array
    {
        $id        = (int) $account->id;
        $children  = [];
        $direction = $account->type->defaultNature() === Nature::Debit ? 1 : -1;
        $amount    = 0;

        if ($account->acceptsEntries) {
            [$debits, $credits] = $sums[$id] ?? [0, 0];
            $amount             = $direction * ($debits - $credits);
        }

        foreach ($tree->childrenOf($id) as $child) {
            $node = $this->node($tree, $child, $sums, $includeZero);

            if ($node !== null) {
                $children[] = $node;
                $amount += $node['amount'];
            }
        }

        if ($amount === 0 && ! $includeZero && $children === [] && ! self::hasMovement($account, $sums)) {
            return null;
        }

        return [
            'account_id' => $id,
            'code'       => $account->code,
            'name'       => $account->name,
            'level'      => $account->level,
            'is_group'   => ! $account->acceptsEntries,
            'contra'     => $account->isContra(),
            'amount'     => $amount,
            'children'   => $children,
        ];
    }

    /**
     * A leaf whose debits and credits cancel still shows, so the statement
     * does not hide an account that did move.
     *
     * @param array<int, array{0: int, 1: int}> $sums
     */
    private static function hasMovement(Account $account, array $sums): bool
    {
        return $account->acceptsEntries && isset($sums[(int) $account->id]);
    }

    /**
     * @param list<array<string, mixed>> $nodes
     */
    private static function sum(array $nodes): int
    {
        return array_sum(array_map(static fn (array $node): int => $node['amount'], $nodes));
    }
}
