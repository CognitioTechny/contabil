<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Calendar;

use CognitioTechny\Contabil\Application\Repository\EntityGuard;
use CognitioTechny\Contabil\Domain\Calendar\FiscalYear;
use CognitioTechny\Contabil\Domain\Calendar\FiscalYearRepositoryInterface;

final class ListFiscalYears
{
    public function __construct(
        private readonly EntityGuard $entities,
        private readonly FiscalYearRepositoryInterface $years,
    ) {
    }

    /**
     * @return list<FiscalYear> Ordered by start date.
     */
    public function execute(int $entityId): array
    {
        $this->entities->assertExists($entityId);

        return $this->years->forEntity($entityId);
    }
}
