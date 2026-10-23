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

    /**
     * Closes the year, posting the closing entry that moves the result to
     * equity. Optional body: `result_account_id`.
     */
    public function close(string $id): ResponseInterface
    {
        return $this->handle('contabil.period.manage', function (int $entity) use ($id): array {
            $body = $this->jsonBody();

            [$year, $closing] = $this->useCases()->manageFiscalYear()->close(
                $entity,
                (int) $id,
                isset($body['result_account_id']) ? (int) $body['result_account_id'] : null,
            );

            return $year->toArray() + ['closing_entry' => $closing?->toArray()];
        });
    }

    /**
     * Reopens a closed year by reversing its closing entry. Body: `reason`.
     */
    public function reopen(string $id): ResponseInterface
    {
        return $this->handle('contabil.period.manage', fn (int $entity): array => $this->useCases()->manageFiscalYear()->reopen(
            $entity,
            (int) $id,
            (string) ($this->jsonBody()['reason'] ?? ''),
        )->toArray());
    }

    public function periods(string $id): ResponseInterface
    {
        return $this->handle('contabil.chart.view', fn (int $entity): array => array_map(
            static fn (Period $period): array => $period->toArray(),
            $this->useCases()->listPeriods()->execute($entity, (int) $id),
        ));
    }
}
