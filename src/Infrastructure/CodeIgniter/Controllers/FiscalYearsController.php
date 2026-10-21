<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Infrastructure\CodeIgniter\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use CognitioTechny\Contabil\Domain\Calendar\FiscalYear;
use CognitioTechny\Contabil\Domain\Calendar\Period;

/**
 * Fiscal calendar API. Reads need `contabil.chart.view`; creating a year needs
 * `contabil.period.manage`.
 */
class FiscalYearsController extends ApiController
{
    public function index(): ResponseInterface
    {
        return $this->handle('contabil.chart.view', fn (int $entity): array => array_map(
            static fn (FiscalYear $year): array => $year->toArray(),
            $this->useCases()->listFiscalYears()->execute($entity),
        ));
    }

    public function create(): ResponseInterface
    {
        return $this->handle('contabil.period.manage', function (int $entity): array {
            $body = $this->jsonBody();

            [$year, $periods] = $this->useCases()->createFiscalYear()->execute(
                $entity,
                (string) ($body['start_date'] ?? ''),
                isset($body['label']) ? (string) $body['label'] : null,
            );

            return $year->toArray() + ['periods' => array_map(static fn (Period $period): array => $period->toArray(), $periods)];
        }, 201);
    }

    public function periods(string $id): ResponseInterface
    {
        return $this->handle('contabil.chart.view', fn (int $entity): array => array_map(
            static fn (Period $period): array => $period->toArray(),
            $this->useCases()->listPeriods()->execute($entity, (int) $id),
        ));
    }
}
