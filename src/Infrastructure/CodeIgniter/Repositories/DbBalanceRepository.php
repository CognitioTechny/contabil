<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Infrastructure\CodeIgniter\Repositories;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CognitioTechny\Contabil\Application\Configuration\TableNames;
use CognitioTechny\Contabil\Domain\Balance\BalanceRepositoryInterface;
use CognitioTechny\Contabil\Domain\Entry\EntryStatus;
use CognitioTechny\Contabil\Domain\Entry\Side;
use DateTimeImmutable;

/**
 * The `balances` cache. Signed with debit positive; the entry lines are the
 * source of truth and {@see rebuild()} recomputes everything from them.
 */
final class DbBalanceRepository implements BalanceRepositoryInterface
{
    private const INSERT_BATCH = 500;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly TableNames $tables,
    ) {
    }

    public function apply(int $accountId, int $periodId, int $debits, int $credits): void
    {
        $net   = $debits - $credits;
        $start = (string) $this->periods()->select('start_date')->where('id', $periodId)->get()->getRow('start_date');
        $row   = $this->balances()->where('account_id', $accountId)->where('period_id', $periodId)->get()->getRowArray();

        if ($row === null) {
            $opening = $this->closingBefore($accountId, $start);

            $this->balances()->insert([
                'account_id'      => $accountId,
                'period_id'       => $periodId,
                'opening_balance' => $opening,
                'total_debits'    => $debits,
                'total_credits'   => $credits,
                'closing_balance' => $opening + $net,
            ]);
        } else {
            $this->balances()
                ->where('account_id', $accountId)
                ->where('period_id', $periodId)
                ->set('total_debits', 'total_debits + ' . $debits, false)
                ->set('total_credits', 'total_credits + ' . $credits, false)
                ->set('closing_balance', 'closing_balance + ' . $net, false)
                ->update();
        }

        if ($net === 0) {
            return;
        }

        // Later periods start from a different balance now.
        $later = array_map(static fn (array $r): int => (int) $r['period_id'], $this->laterPeriodRows($accountId, $start));

        if ($later !== []) {
            $this->balances()
                ->where('account_id', $accountId)
                ->whereIn('period_id', $later)
                ->set('opening_balance', 'opening_balance + ' . $net, false)
                ->set('closing_balance', 'closing_balance + ' . $net, false)
                ->update();
        }
    }

    public function rebuild(int $entityId): int
    {
        $this->balances()->whereIn('account_id', fn (BaseBuilder $sub): BaseBuilder => $sub
            ->select('id')->from($this->tables->name('accounts'))->where('entity_id', $entityId))->delete();

        $rows = $this->db->table($this->tables->name('entry_lines') . ' l')
            ->select('l.account_id, p.id AS period_id, p.start_date, l.side, SUM(l.amount) AS total')
            ->join($this->tables->name('entries') . ' e', 'e.id = l.entry_id')
            ->join($this->tables->name('periods') . ' p', 'p.fiscal_year_id = e.fiscal_year_id AND e.accrual_date >= p.start_date AND e.accrual_date <= p.end_date')
            ->where('e.entity_id', $entityId)
            ->whereIn('e.status', [EntryStatus::Posted->value, EntryStatus::Reversed->value])
            ->groupBy('l.account_id, p.id, p.start_date, l.side')
            ->orderBy('l.account_id', 'ASC')
            ->orderBy('p.start_date', 'ASC')
            ->get()
            ->getResultArray();

        // account => period => [start, debits, credits]
        $grid = [];

        foreach ($rows as $row) {
            $cell = &$grid[(int) $row['account_id']][(int) $row['period_id']];
            $cell ??= [(string) $row['start_date'], 0, 0];
            $cell[Side::from((string) $row['side']) === Side::Debit ? 1 : 2] += (int) $row['total'];
            unset($cell);
        }

        $batch   = [];
        $written = 0;

        foreach ($grid as $accountId => $periods) {
            uasort($periods, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

            $running = 0;

            foreach ($periods as $periodId => [, $debits, $credits]) {
                $batch[] = [
                    'account_id'      => $accountId,
                    'period_id'       => $periodId,
                    'opening_balance' => $running,
                    'total_debits'    => $debits,
                    'total_credits'   => $credits,
                    'closing_balance' => $running + $debits - $credits,
                ];

                $running += $debits - $credits;

                if (count($batch) >= self::INSERT_BATCH) {
                    $this->balances()->insertBatch($batch);
                    $written += count($batch);
                    $batch = [];
                }
            }
        }

        if ($batch !== []) {
            $this->balances()->insertBatch($batch);
            $written += count($batch);
        }

        return $written;
    }

    public function closingAsOf(int $entityId, DateTimeImmutable $date): ?array
    {
        $isPeriodEnd = $this->db->table($this->tables->name('periods') . ' p')
            ->join($this->tables->name('fiscal_years') . ' y', 'y.id = p.fiscal_year_id')
            ->where('y.entity_id', $entityId)
            ->where('p.end_date', $date->format('Y-m-d'))
            ->countAllResults() > 0;

        if (! $isPeriodEnd) {
            return null;
        }

        $rows = $this->db->table($this->tables->name('balances') . ' b')
            ->select('b.account_id, b.closing_balance, p.start_date')
            ->join($this->tables->name('periods') . ' p', 'p.id = b.period_id')
            ->join($this->tables->name('accounts') . ' a', 'a.id = b.account_id')
            ->where('a.entity_id', $entityId)
            ->where('p.start_date <=', $date->format('Y-m-d'))
            ->orderBy('p.start_date', 'ASC')
            ->get()
            ->getResultArray();

        $closing = [];

        foreach ($rows as $row) {
            $closing[(int) $row['account_id']] = (int) $row['closing_balance'];
        }

        return $closing;
    }

    private function closingBefore(int $accountId, string $start): int
    {
        $row = $this->db->table($this->tables->name('balances') . ' b')
            ->select('b.closing_balance')
            ->join($this->tables->name('periods') . ' p', 'p.id = b.period_id')
            ->where('b.account_id', $accountId)
            ->where('p.start_date <', $start)
            ->orderBy('p.start_date', 'DESC')
            ->limit(1)
            ->get()
            ->getRowArray();

        return $row === null ? 0 : (int) $row['closing_balance'];
    }

    /**
     * @return list<array{period_id: int|string}>
     */
    private function laterPeriodRows(int $accountId, string $start): array
    {
        return $this->db->table($this->tables->name('balances') . ' b')
            ->select('b.period_id')
            ->join($this->tables->name('periods') . ' p', 'p.id = b.period_id')
            ->where('b.account_id', $accountId)
            ->where('p.start_date >', $start)
            ->get()
            ->getResultArray();
    }

    private function balances(): BaseBuilder
    {
        return $this->db->table($this->tables->name('balances'));
    }

    private function periods(): BaseBuilder
    {
        return $this->db->table($this->tables->name('periods'));
    }
}
