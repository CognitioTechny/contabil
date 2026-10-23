<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Infrastructure\CodeIgniter\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use CognitioTechny\Contabil\Domain\Calendar\FiscalYear;
use CognitioTechny\Contabil\Domain\Calendar\Period;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;

/**
 * Fiscal calendar API. Reads need `contabil.chart.view`; creating, opening,
 * closing and reopening a year need `contabil.period.manage`. Reading the
 * opening balances needs `contabil.entry.view`; posting them needs
 * `contabil.period.manage` and `contabil.entry.post`.
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

    /**
     * Opens the fiscal year that follows `{id}`. Optional body: `label`.
     */
    public function openNext(string $id): ResponseInterface
    {
        return $this->handle('contabil.period.manage', function (int $entity) use ($id): array {
            $body = $this->jsonBody();

            [$year, $periods] = $this->useCases()->manageFiscalYear()->openNext(
                $entity,
                (int) $id,
                isset($body['label']) ? (string) $body['label'] : null,
            );

            return $year->toArray() + ['periods' => array_map(static fn (Period $period): array => $period->toArray(), $periods)];
        }, 201);
    }

    /**
     * The opening entry of the year in force, or null.
     */
    public function openingBalances(string $id): ResponseInterface
    {
        return $this->handle('contabil.entry.view', fn (int $entity): array => [
            'entry' => $this->useCases()->openingBalances()->current($entity, (int) $id)?->toArray(),
        ]);
    }

    /**
     * Posts the opening balances of the entity's first year. Body: `balances`,
     * optional `balancing_account_id`, `description` and `replace`.
     */
    public function recordOpeningBalances(string $id): ResponseInterface
    {
        return $this->handle('contabil.period.manage', function (int $entity) use ($id): array {
            $this->also('contabil.entry.post', $entity);

            $body     = $this->jsonBody();
            $balances = $body['balances'] ?? null;

            if (! is_array($balances) || ! array_is_list($balances)) {
                throw new ValidationException('balances must be a list.');
            }

            $entry = $this->useCases()->openingBalances()->record(
                $entity,
                (int) $id,
                $balances,
                isset($body['balancing_account_id']) ? (int) $body['balancing_account_id'] : null,
                isset($body['description']) ? (string) $body['description'] : null,
                (bool) ($body['replace'] ?? false),
            );

            return ['entry' => $entry->toArray()];
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
