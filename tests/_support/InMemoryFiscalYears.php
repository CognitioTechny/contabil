<?php

declare(strict_types=1);

namespace Tests\Support;

use CognitioTechny\Contabil\Application\Repository\TransactionRunnerInterface;
use CognitioTechny\Contabil\Domain\Calendar\FiscalYear;
use CognitioTechny\Contabil\Domain\Calendar\FiscalYearRepositoryInterface;
use CognitioTechny\Contabil\Domain\Calendar\Period;
use DateTimeImmutable;
use Throwable;

final class InMemoryFiscalYears implements FiscalYearRepositoryInterface, TransactionRunnerInterface
{
    /**
     * @var array<int, FiscalYear>
     */
    private array $years = [];

    /**
     * @var array<int, list<Period>>
     */
    private array $periods = [];

    private int $nextId = 1;

    public function forEntity(int $entityId): array
    {
        $years = array_values(array_filter($this->years, static fn (FiscalYear $y): bool => $y->entityId === $entityId));
        usort($years, static fn (FiscalYear $a, FiscalYear $b): int => $a->startDate <=> $b->startDate);

        return $years;
    }

    public function find(int $entityId, int $id): ?FiscalYear
    {
        $year = $this->years[$id] ?? null;

        return $year !== null && $year->entityId === $entityId ? $year : null;
    }

    public function insert(FiscalYear $year, array $periods): array
    {
        $year                     = $year->withId($this->nextId++);
        $this->years[$year->id]   = $year;
        $this->periods[$year->id] = array_map(
            fn (Period $period): Period => $period->withFiscalYearId($year->id)->withId($this->nextId++),
            $periods,
        );

        return [$year, $this->periods[$year->id]];
    }

    public function periodsOf(int $fiscalYearId): array
    {
        return $this->periods[$fiscalYearId] ?? [];
    }

    public function periodContaining(int $entityId, DateTimeImmutable $date): ?array
    {
        foreach ($this->forEntity($entityId) as $year) {
            foreach ($this->periods[$year->id] ?? [] as $period) {
                if ($period->contains($date)) {
                    return [$year, $period];
                }
            }
        }

        return null;
    }

    public function findPeriod(int $entityId, int $periodId): ?array
    {
        foreach ($this->forEntity($entityId) as $year) {
            foreach ($this->periods[$year->id] ?? [] as $period) {
                if ($period->id === $periodId) {
                    return [$year, $period];
                }
            }
        }

        return null;
    }

    public function savePeriod(Period $period): void
    {
        foreach ($this->periods[$period->fiscalYearId] as $index => $stored) {
            if ($stored->id === $period->id) {
                $this->periods[$period->fiscalYearId][$index] = $period;
            }
        }
    }

    public function saveYear(FiscalYear $year): void
    {
        $this->years[$year->id] = $year;
    }

    public function run(callable $work): mixed
    {
        $snapshot = [$this->years, $this->periods, $this->nextId];

        try {
            return $work();
        } catch (Throwable $e) {
            [$this->years, $this->periods, $this->nextId] = $snapshot;

            throw $e;
        }
    }
}
