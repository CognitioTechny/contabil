<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Infrastructure\CodeIgniter\Commands;

use CodeIgniter\CLI\CLI;
use CognitioTechny\Contabil\Domain\Exception\ContabilException;

/**
 * Opens a fiscal year: the first one of an entity with `--start`, otherwise
 * the year after the entity's latest.
 */
class OpenYearCommand extends ContabilCommand
{
    protected $name        = 'contabil:open-year';
    protected $description = 'Opens the first fiscal year of an entity (--start) or the one after its latest.';
    protected $usage       = 'contabil:open-year [--entity <id>] [--start YYYY-MM-DD] [--label <label>]';
    protected $options     = [
        '--entity' => 'Entity id; defaults to the configured defaultEntityId.',
        '--start'  => 'First day of the year to create; required when the entity has no year yet.',
        '--label'  => 'Label of the new year; defaults to its starting year.',
    ];

    public function run(array $params)
    {
        if (! $this->commandsEnabled()) {
            CLI::error('The Contabil commands are disabled in configuration.');

            return EXIT_ERROR;
        }

        try {
            $entity = $this->requiredEntity($params);
            $start  = $this->option($params, 'start');
            $label  = $this->option($params, 'label');
            $uc     = service('contabil');

            if ($start !== null) {
                [$year] = $uc->createFiscalYear()->execute($entity, $start, $label);
            } else {
                $years = $uc->listFiscalYears()->execute($entity);

                if ($years === []) {
                    CLI::error('The entity has no fiscal year yet; pass --start with the first day of its first year.');

                    return EXIT_ERROR;
                }

                [$year] = $uc->manageFiscalYear()->openNext($entity, (int) $years[array_key_last($years)]->id, $label);
            }
        } catch (ContabilException $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        CLI::write(sprintf('Fiscal year %s (%s to %s) opened with id %d.', $year->label, $year->startDate->format('Y-m-d'), $year->endDate->format('Y-m-d'), (int) $year->id), 'green');

        return EXIT_SUCCESS;
    }
}
