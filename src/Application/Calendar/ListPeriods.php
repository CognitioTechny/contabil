<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Calendar;

use CognitioTechny\Contabil\Domain\Calendar\FiscalYearRepositoryInterface;
use CognitioTechny\Contabil\Domain\Calendar\Period;
use CognitioTechny\Contabil\Domain\Exception\NotFoundException;

final class ListPeriods
{
    public function __construct(private readonly FiscalYearRepositoryInterface $years)
    {
    }

    /**
     * @return list<Period> The periods of the year, ordered by start date.
     */
    public function execute(int $entityId, int $fiscalYearId): array
    {
        if ($this->years->find($entityId, $fiscalYearId) === null) {
            throw new NotFoundException(sprintf('Fiscal year %d does not exist in this entity.', $fiscalYearId));
        }

        return $this->years->periodsOf($fiscalYearId);
    }
}
