<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Domain\Calendar;

interface FiscalYearRepositoryInterface
{
    /**
     * @return list<FiscalYear> Ordered by start date.
     */
    public function forEntity(int $entityId): array;

    public function find(int $entityId, int $id): ?FiscalYear;

    /**
     * Stores a new year and its periods together.
     *
     * @param list<Period> $periods
     *
     * @return array{0: FiscalYear, 1: list<Period>} Both with their ids.
     */
    public function insert(FiscalYear $year, array $periods): array;

    /**
     * @return list<Period> Ordered by start date.
     */
    public function periodsOf(int $fiscalYearId): array;
}
