<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Infrastructure\CodeIgniter\Commands;

use CodeIgniter\CLI\CLI;
use CognitioTechny\Contabil\Domain\Calendar\FiscalYear;
use CognitioTechny\Contabil\Domain\Calendar\PeriodStatus;
use CognitioTechny\Contabil\Domain\Calendar\YearStatus;
use CognitioTechny\Contabil\Domain\Exception\ContabilException;
use CognitioTechny\Contabil\Domain\Exception\NotFoundException;

/**
 * Closes a fiscal year, optionally closing its open periods first and opening
 * the next year afterwards.
 */
class CloseYearCommand extends ContabilCommand
{
    protected $name        = 'contabil:close-year';
    protected $description = 'Closes a fiscal year, moving its result to equity.';
    protected $usage       = 'contabil:close-year [--entity <id>] [--year <label|id>] [--result-account <code>] [--close-periods] [--open-next]';
    protected $options     = [
        '--entity'         => 'Entity id; defaults to the configured defaultEntityId.',
        '--year'           => 'Year to close, by label or id; defaults to the earliest open year.',
        '--result-account' => 'Code of the equity account for the result; defaults to resultAccountCode.',
        '--close-periods'  => 'Closes the open periods of the year first.',
        '--open-next'      => 'Opens the next fiscal year afterwards when it does not exist.',
    ];

    public function run(array $params)
    {
        if (! $this->commandsEnabled()) {
            CLI::error('The Contabil commands are disabled in configuration.');

            return EXIT_ERROR;
        }

        $uc = service('contabil');

        try {
            $entity    = $this->requiredEntity($params);
            $reference = $this->option($params, 'year');
            $year      = $reference !== null ? $this->fiscalYear($entity, $reference) : $this->earliestOpen($entity);
            $code      = $this->option($params, 'result-account');
            $result    = $code === null ? null : $this->accountId($entity, $code);

            if (array_key_exists('close-periods', $params)) {
                $periods = $uc->listPeriods()->execute($entity, (int) $year->id);

                foreach (array_slice($periods, 0, -1) as $period) {
                    if ($period->status === PeriodStatus::Open) {
                        $uc->managePeriod()->close($entity, (int) $period->id);
                    }
                }
            }

            [$closed, $entry] = $uc->manageFiscalYear()->close($entity, (int) $year->id, $result);

            CLI::write(
                sprintf('Fiscal year %s closed', $closed->label) . ($entry === null ? ' without a closing entry.' : sprintf(' with closing entry %d.', (int) $entry->number)),
                'green',
            );

            if (array_key_exists('open-next', $params)) {
                $this->openNext($entity, $closed);
            }
        } catch (ContabilException $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        return EXIT_SUCCESS;
    }

    private function earliestOpen(int $entity): FiscalYear
    {
        foreach (service('contabil')->listFiscalYears()->execute($entity) as $year) {
            if ($year->status === YearStatus::Open) {
                return $year;
            }
        }

        throw new NotFoundException(sprintf('Entity %d has no open fiscal year.', $entity));
    }

    private function openNext(int $entity, FiscalYear $year): void
    {
        foreach (service('contabil')->listFiscalYears()->execute($entity) as $other) {
            if ($other->startDate > $year->endDate) {
                CLI::write(sprintf('The next fiscal year, %s, already exists.', $other->label));

                return;
            }
        }

        [$next] = service('contabil')->manageFiscalYear()->openNext($entity, (int) $year->id);

        CLI::write(sprintf('Fiscal year %s opened with id %d.', $next->label, (int) $next->id), 'green');
    }
}
