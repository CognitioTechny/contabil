<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Infrastructure\CodeIgniter\Repositories;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CognitioTechny\Contabil\Application\Configuration\TableNames;
use CognitioTechny\Contabil\Application\Reports\DimensionFilter;
use CognitioTechny\Contabil\Application\Reports\LedgerLine;
use CognitioTechny\Contabil\Application\Reports\ReportReaderInterface;
use CognitioTechny\Contabil\Domain\Chart\AccountType;
use CognitioTechny\Contabil\Domain\Entry\EntryStatus;
use CognitioTechny\Contabil\Domain\Entry\JournalEntry;
use CognitioTechny\Contabil\Domain\Entry\Settlement;
use CognitioTechny\Contabil\Domain\Entry\Side;
use DateTimeImmutable;

/**
 * Read-only queries for the books and statements. It never writes, so the
 * connection may point at a read replica.
 */
final class DbReportReader implements ReportReaderInterface
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly TableNames $tables,
    ) {
    }

    public function accrualSums(int $entityId, ?DateTimeImmutable $from, ?DateTimeImmutable $to, ?DimensionFilter $dimension = null): array
    {
        $rows = $this->lineScope($entityId, $from, $to, $dimension)
            ->select('l.account_id, l.side, SUM(l.amount) AS total')
            ->groupBy('l.account_id, l.side')
            ->get()
            ->getResultArray();

        return self::pairs($rows);
    }

    public function ledgerLines(int $entityId, array $accountIds, ?DateTimeImmutable $from, ?DateTimeImmutable $to, ?array $after, int $limit, ?DimensionFilter $dimension = null): array
    {
        if ($accountIds === []) {
            return [];
        }

        $builder = $this->lineScope($entityId, $from, $to, $dimension)
            ->select('l.id AS line_id, e.id AS entry_id, e.number, e.accrual_date, e.description, l.line_description, l.account_id, l.side, l.amount, e.source_type, e.source_id')
            ->whereIn('l.account_id', $accountIds);

        if ($after !== null) {
            [$date, $entryId, $lineId] = $after;

            $builder->groupStart()
                ->where('e.accrual_date >', $date)
                ->orGroupStart()->where('e.accrual_date', $date)->where('e.id >', $entryId)->groupEnd()
                ->orGroupStart()->where('e.accrual_date', $date)->where('e.id', $entryId)->where('l.id >', $lineId)->groupEnd()
                ->groupEnd();
        }

        $rows = $builder->orderBy('e.accrual_date', 'ASC')->orderBy('e.id', 'ASC')->orderBy('l.id', 'ASC')->limit($limit)->get()->getResultArray();

        return array_map(static fn (array $row): LedgerLine => new LedgerLine(
            (int) $row['line_id'],
            (int) $row['entry_id'],
            (int) $row['number'],
            new DateTimeImmutable((string) $row['accrual_date']),
            (string) $row['description'],
            $row['line_description'] === null ? null : (string) $row['line_description'],
            (int) $row['account_id'],
            Side::from((string) $row['side']),
            (int) $row['amount'],
            $row['source_type'] === null ? null : (string) $row['source_type'],
            $row['source_id'] === null ? null : (string) $row['source_id'],
        ), $rows);
    }

    public function sumsFor(int $entityId, array $accountIds, ?DateTimeImmutable $from, ?DateTimeImmutable $to, ?array $upTo = null, ?DimensionFilter $dimension = null): array
    {
        if ($accountIds === []) {
            return [0, 0];
        }

        $builder = $this->lineScope($entityId, $from, $to, $dimension)
            ->select('l.side, SUM(l.amount) AS total')
            ->whereIn('l.account_id', $accountIds);

        if ($upTo !== null) {
            [$date, $entryId, $lineId] = $upTo;

            $builder->groupStart()
                ->where('e.accrual_date <', $date)
                ->orGroupStart()->where('e.accrual_date', $date)->where('e.id <', $entryId)->groupEnd()
                ->orGroupStart()->where('e.accrual_date', $date)->where('e.id', $entryId)->where('l.id <=', $lineId)->groupEnd()
                ->groupEnd();
        }

        $sums = [0, 0];

        foreach ($builder->groupBy('l.side')->get()->getResultArray() as $row) {
            $sums[Side::from((string) $row['side']) === Side::Debit ? 0 : 1] = (int) $row['total'];
        }

        return $sums;
    }

    public function journal(int $entityId, ?DateTimeImmutable $from, ?DateTimeImmutable $to, ?array $after, int $limit): array
    {
        $builder = $this->db->table($this->tables->name('entries') . ' e')
            ->select('e.*')
            ->where('e.entity_id', $entityId)
            ->whereIn('e.status', self::inLedger());

        if ($from !== null) {
            $builder->where('e.accrual_date >=', $from->format('Y-m-d'));
        }

        if ($to !== null) {
            $builder->where('e.accrual_date <=', $to->format('Y-m-d'));
        }

        if ($after !== null) {
            [$date, $number] = $after;

            $builder->groupStart()
                ->where('e.accrual_date >', $date)
                ->orGroupStart()->where('e.accrual_date', $date)->where('e.number >', $number)->groupEnd()
                ->groupEnd();
        }

        return $this->withLines($builder->orderBy('e.accrual_date', 'ASC')->orderBy('e.number', 'ASC')->limit($limit)->get()->getResultArray());
    }

    public function bySource(int $entityId, string $sourceType, string $sourceId): array
    {
        return $this->withLines($this->db->table($this->tables->name('entries'))
            ->where('entity_id', $entityId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->whereIn('status', self::inLedger())
            ->orderBy('chain_position', 'ASC')
            ->get()
            ->getResultArray());
    }

    public function directCashSums(int $entityId, ?DateTimeImmutable $from, ?DateTimeImmutable $to, ?DimensionFilter $dimension = null): array
    {
        $builder = $this->db->table($this->tables->name('entry_lines') . ' l')
            ->select('l.account_id, l.side, SUM(l.amount) AS total')
            ->join($this->tables->name('entries') . ' e', 'e.id = l.entry_id')
            ->join($this->tables->name('accounts') . ' a', 'a.id = l.account_id')
            ->where('e.entity_id', $entityId)
            ->whereIn('e.status', self::inLedger())
            ->where('e.cash_date IS NOT NULL')
            ->whereIn('a.type', self::resultTypes());

        $this->filterDimension($builder, $dimension);

        if ($from !== null) {
            $builder->where('e.cash_date >=', $from->format('Y-m-d'));
        }

        if ($to !== null) {
            $builder->where('e.cash_date <=', $to->format('Y-m-d'));
        }

        return self::pairs($builder->groupBy('l.account_id, l.side')->get()->getResultArray());
    }

    public function settlementsUpTo(int $entityId, ?DateTimeImmutable $to): array
    {
        $builder = $this->db->table($this->tables->name('settlements') . ' s')
            ->select('s.id, s.settlement_entry_id, s.settled_entry_id, s.amount, s.date')
            ->join($this->tables->name('entries') . ' paying', 'paying.id = s.settlement_entry_id')
            ->join($this->tables->name('entries') . ' settled', 'settled.id = s.settled_entry_id')
            ->where('settled.entity_id', $entityId)
            ->where('paying.status <>', EntryStatus::Reversed->value)
            ->whereIn('settled.status', self::inLedger());

        if ($to !== null) {
            $builder->where('s.date <=', $to->format('Y-m-d'));
        }

        $rows = $builder->orderBy('s.date', 'ASC')->orderBy('s.id', 'ASC')->get()->getResultArray();

        return array_map(static fn (array $row): Settlement => new Settlement(
            (int) $row['id'],
            (int) $row['settlement_entry_id'],
            (int) $row['settled_entry_id'],
            (int) $row['amount'],
            new DateTimeImmutable((string) $row['date']),
        ), $rows);
    }

    public function settledProfiles(int $entityId, array $entryIds, ?DimensionFilter $dimension = null): array
    {
        if ($entryIds === []) {
            return [];
        }

        $rows = $this->db->table($this->tables->name('entry_lines') . ' l')
            ->select('l.id, l.entry_id, l.account_id, l.side, l.amount, a.type')
            ->join($this->tables->name('entries') . ' e', 'e.id = l.entry_id')
            ->join($this->tables->name('accounts') . ' a', 'a.id = l.account_id')
            ->where('e.entity_id', $entityId)
            ->whereIn('l.entry_id', $entryIds)
            ->orderBy('l.entry_id', 'ASC')
            ->orderBy('l.position', 'ASC')
            ->get()
            ->getResultArray();

        $tagged = $dimension === null ? null : array_flip($this->linesWithDimension($entityId, $entryIds, $dimension));

        $profiles = [];

        foreach ($rows as $row) {
            $id   = (int) $row['entry_id'];
            $side = Side::from((string) $row['side']);

            $profiles[$id] ??= ['total' => 0, 'lines' => []];

            if ($side === Side::Debit) {
                $profiles[$id]['total'] += (int) $row['amount'];
            }

            if (in_array((string) $row['type'], self::resultTypes(), true) && ($tagged === null || isset($tagged[(int) $row['id']]))) {
                $profiles[$id]['lines'][] = [(int) $row['account_id'], $side, (int) $row['amount']];
            }
        }

        return $profiles;
    }

    /**
     * Lines of ledger entries of the entity inside an accrual date range.
     */
    private function lineScope(int $entityId, ?DateTimeImmutable $from, ?DateTimeImmutable $to, ?DimensionFilter $dimension = null): BaseBuilder
    {
        $builder = $this->db->table($this->tables->name('entry_lines') . ' l')
            ->join($this->tables->name('entries') . ' e', 'e.id = l.entry_id')
            ->where('e.entity_id', $entityId)
            ->whereIn('e.status', self::inLedger());

        $this->filterDimension($builder, $dimension);

        if ($from !== null) {
            $builder->where('e.accrual_date >=', $from->format('Y-m-d'));
        }

        if ($to !== null) {
            $builder->where('e.accrual_date <=', $to->format('Y-m-d'));
        }

        return $builder;
    }

    /**
     * Keeps only lines (alias `l`) tagged with the dimension.
     */
    private function filterDimension(BaseBuilder $builder, ?DimensionFilter $dimension): void
    {
        if ($dimension === null) {
            return;
        }

        // A raw subquery: a builder closure would make CodeIgniter re-prefix the outer aliases.
        $sql = sprintf(
            'l.id IN (SELECT line_id FROM %s WHERE dimension_type = %s',
            $this->db->protectIdentifiers($this->db->prefixTable($this->tables->name('entry_line_dimensions')), false, false),
            $this->db->escape($dimension->type),
        );

        if ($dimension->id !== null) {
            $sql .= ' AND dimension_id = ' . $this->db->escape($dimension->id);
        }

        $builder->where($sql . ')', null, false);
    }

    /**
     * Ids of the lines of the given entries tagged with the dimension.
     *
     * @param list<int> $entryIds
     *
     * @return list<int>
     */
    private function linesWithDimension(int $entityId, array $entryIds, DimensionFilter $dimension): array
    {
        $builder = $this->db->table($this->tables->name('entry_line_dimensions') . ' d')
            ->select('d.line_id')
            ->join($this->tables->name('entries') . ' e', 'e.id = d.entry_id')
            ->where('e.entity_id', $entityId)
            ->whereIn('d.entry_id', $entryIds)
            ->where('d.dimension_type', $dimension->type);

        if ($dimension->id !== null) {
            $builder->where('d.dimension_id', $dimension->id);
        }

        return array_map(static fn (array $row): int => (int) $row['line_id'], $builder->get()->getResultArray());
    }

    /**
     * @param list<array<string, mixed>> $entryRows
     *
     * @return list<JournalEntry>
     */
    private function withLines(array $entryRows): array
    {
        if ($entryRows === []) {
            return [];
        }

        $byEntry = [];

        $lineRows = $this->db->table($this->tables->name('entry_lines'))
            ->whereIn('entry_id', array_map(static fn (array $row): int => (int) $row['id'], $entryRows))
            ->orderBy('entry_id', 'ASC')
            ->orderBy('position', 'ASC')
            ->get()
            ->getResultArray();

        $dimensionsByLine = [];

        if ($lineRows !== []) {
            $dimensionRows = $this->db->table($this->tables->name('entry_line_dimensions'))
                ->select('line_id, dimension_type, dimension_id')
                ->whereIn('entry_id', array_map(static fn (array $row): int => (int) $row['id'], $entryRows))
                ->get()
                ->getResultArray();

            foreach ($dimensionRows as $dimensionRow) {
                $dimensionsByLine[(int) $dimensionRow['line_id']][(string) $dimensionRow['dimension_type']] = (string) $dimensionRow['dimension_id'];
            }
        }

        foreach ($lineRows as $lineRow) {
            $lineRow['dimensions']                 = $dimensionsByLine[(int) $lineRow['id']] ?? [];
            $byEntry[(int) $lineRow['entry_id']][] = $lineRow;
        }

        return array_map(static fn (array $row) => EntryRows::entry($row, $byEntry[(int) $row['id']] ?? []), $entryRows);
    }

    /**
     * @param list<array<string, mixed>> $rows Rows of account_id, side, total.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    private static function pairs(array $rows): array
    {
        $sums = [];

        foreach ($rows as $row) {
            $id = (int) $row['account_id'];

            $sums[$id] ??= [0, 0];
            $sums[$id][Side::from((string) $row['side']) === Side::Debit ? 0 : 1] = (int) $row['total'];
        }

        return $sums;
    }

    /**
     * @return list<string>
     */
    private static function inLedger(): array
    {
        return [EntryStatus::Posted->value, EntryStatus::Reversed->value];
    }

    /**
     * @return list<string>
     */
    private static function resultTypes(): array
    {
        return [AccountType::Revenue->value, AccountType::Expense->value];
    }
}
