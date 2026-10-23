<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Infrastructure\CodeIgniter\Repositories;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CognitioTechny\Contabil\Application\Configuration\TableNames;
use CognitioTechny\Contabil\Application\Ports\ClockInterface;
use CognitioTechny\Contabil\Domain\Calendar\FiscalYear;
use CognitioTechny\Contabil\Domain\Calendar\FiscalYearRepositoryInterface;
use CognitioTechny\Contabil\Domain\Calendar\Period;
use CognitioTechny\Contabil\Domain\Calendar\PeriodStatus;
use CognitioTechny\Contabil\Domain\Calendar\YearStatus;
use DateTimeImmutable;

final class DbFiscalYearRepository implements FiscalYearRepositoryInterface
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly TableNames $tables,
        private readonly ClockInterface $clock,
    ) {
    }

    public function forEntity(int $entityId): array
    {
        $rows = $this->years()->where('entity_id', $entityId)->orderBy('start_date', 'ASC')->get()->getResultArray();

        return array_map(self::hydrateYear(...), $rows);
    }

    public function find(int $entityId, int $id): ?FiscalYear
    {
        $row = $this->years()->where('entity_id', $entityId)->where('id', $id)->get()->getRowArray();

        return $row === null ? null : self::hydrateYear($row);
    }

    public function insert(FiscalYear $year, array $periods): array
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $this->years()->insert([
            'entity_id'  => $year->entityId,
            'label'      => $year->label,
            'start_date' => $year->startDate->format('Y-m-d'),
            'end_date'   => $year->endDate->format('Y-m-d'),
            'status'     => $year->status->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $year = $year->withId((int) $this->db->insertID());

        $this->periods()->insertBatch(array_map(static fn (Period $period): array => [
            'fiscal_year_id' => $year->id,
            'start_date'     => $period->startDate->format('Y-m-d'),
            'end_date'       => $period->endDate->format('Y-m-d'),
            'status'         => $period->status->value,
            'created_at'     => $now,
            'updated_at'     => $now,
        ], $periods));

        return [$year, $this->periodsOf((int) $year->id)];
    }

    public function periodsOf(int $fiscalYearId): array
    {
        $rows = $this->periods()->where('fiscal_year_id', $fiscalYearId)->orderBy('start_date', 'ASC')->get()->getResultArray();

        return array_map(static fn (array $row): Period => new Period(
            (int) $row['id'],
            (int) $row['fiscal_year_id'],
            new DateTimeImmutable((string) $row['start_date']),
            new DateTimeImmutable((string) $row['end_date']),
            PeriodStatus::from((string) $row['status']),
        ), $rows);
    }

    public function periodContaining(int $entityId, DateTimeImmutable $date): ?array
    {
        $row = $this->db->table($this->tables->name('periods') . ' p')
            ->select('p.id AS period_id, p.fiscal_year_id, p.start_date AS period_start, p.end_date AS period_end, p.status AS period_status, y.*')
            ->join($this->tables->name('fiscal_years') . ' y', 'y.id = p.fiscal_year_id')
            ->where('y.entity_id', $entityId)
            ->where('p.start_date <=', $date->format('Y-m-d'))
            ->where('p.end_date >=', $date->format('Y-m-d'))
            ->get()
            ->getRowArray();

        return $row === null ? null : self::hydratePair($row);
    }

    public function findPeriod(int $entityId, int $periodId): ?array
    {
        $row = $this->db->table($this->tables->name('periods') . ' p')
            ->select('p.id AS period_id, p.fiscal_year_id, p.start_date AS period_start, p.end_date AS period_end, p.status AS period_status, y.*')
            ->join($this->tables->name('fiscal_years') . ' y', 'y.id = p.fiscal_year_id')
            ->where('y.entity_id', $entityId)
            ->where('p.id', $periodId)
            ->get()
            ->getRowArray();

        return $row === null ? null : self::hydratePair($row);
    }

    public function savePeriod(Period $period): void
    {
        $this->periods()->where('id', $period->id)->update([
            'status'     => $period->status->value,
            'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);
    }

    public function saveYear(FiscalYear $year): void
    {
        $this->years()->where('id', $year->id)->update([
            'status'     => $year->status->value,
            'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);
    }

    private function years(): BaseBuilder
    {
        return $this->db->table($this->tables->name('fiscal_years'));
    }

    private function periods(): BaseBuilder
    {
        return $this->db->table($this->tables->name('periods'));
    }

    /**
     * @param array<string, mixed> $row A period joined with its year (`y.*`).
     *
     * @return array{0: FiscalYear, 1: Period}
     */
    private static function hydratePair(array $row): array
    {
        return [
            self::hydrateYear($row),
            new Period(
                (int) $row['period_id'],
                (int) $row['fiscal_year_id'],
                new DateTimeImmutable((string) $row['period_start']),
                new DateTimeImmutable((string) $row['period_end']),
                PeriodStatus::from((string) $row['period_status']),
            ),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrateYear(array $row): FiscalYear
    {
        return new FiscalYear(
            (int) $row['id'],
            (int) $row['entity_id'],
            (string) $row['label'],
            new DateTimeImmutable((string) $row['start_date']),
            new DateTimeImmutable((string) $row['end_date']),
            YearStatus::from((string) $row['status']),
        );
    }
}
