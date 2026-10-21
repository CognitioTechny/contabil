<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Calendar;

use CognitioTechny\Contabil\Application\Repository\EntityGuard;
use CognitioTechny\Contabil\Application\Repository\TransactionRunnerInterface;
use CognitioTechny\Contabil\Domain\Calendar\FiscalYear;
use CognitioTechny\Contabil\Domain\Calendar\FiscalYearRepositoryInterface;
use CognitioTechny\Contabil\Domain\Calendar\Period;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;
use DateTimeImmutable;

final class CreateFiscalYear
{
    public function __construct(
        private readonly EntityGuard $entities,
        private readonly FiscalYearRepositoryInterface $years,
        private readonly TransactionRunnerInterface $transactions,
    ) {
    }

    /**
     * Creates an open fiscal year of twelve months and its twelve monthly
     * periods, without gaps. The years of an entity never overlap.
     *
     * @param string      $startDate First day of a month, as `YYYY-MM-DD`.
     * @param string|null $label     Defaults to the year (`2026`), or `2026/2027` when the
     *                               year does not start in January.
     *
     * @return array{0: FiscalYear, 1: list<Period>}
     */
    public function execute(int $entityId, string $startDate, ?string $label = null): array
    {
        $this->entities->assertExists($entityId);

        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);

        if ($start === false || $start->format('Y-m-d') !== $startDate) {
            throw new ValidationException('The start date must be a valid date as YYYY-MM-DD.');
        }

        [$year, $periods] = FiscalYear::start($entityId, $start, $label);

        return $this->transactions->run(function () use ($year, $periods, $entityId): array {
            $year->assertNoOverlap($this->years->forEntity($entityId));

            return $this->years->insert($year, $periods);
        });
    }
}
