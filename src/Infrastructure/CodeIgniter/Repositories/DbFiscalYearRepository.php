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

    private function years(): BaseBuilder
    {
        return $this->db->table($this->tables->name('fiscal_years'));
    }

    private function periods(): BaseBuilder
    {
        return $this->db->table($this->tables->name('periods'));
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
