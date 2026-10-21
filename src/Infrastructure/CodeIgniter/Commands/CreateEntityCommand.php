<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Infrastructure\CodeIgniter\Commands;

use CodeIgniter\CLI\CLI;
use CognitioTechny\Contabil\Domain\Exception\ContabilException;

class CreateEntityCommand extends ContabilCommand
{
    protected $name        = 'contabil:create-entity';
    protected $description = 'Registers an accounted entity and prints its id.';
    protected $usage       = 'contabil:create-entity --name <name> [--tax-id <id>] [--currency <ISO>] [--basis ACCRUAL|CASH]';
    protected $options     = [
        '--name'     => 'Name of the entity (required).',
        '--tax-id'   => 'Tax identifier.',
        '--currency' => 'ISO 4217 code; defaults to the configured currency.',
        '--basis'    => 'ACCRUAL or CASH; defaults to the configured basis.',
    ];

    public function run(array $params)
    {
        if (! $this->commandsEnabled()) {
            CLI::error('The Contabil commands are disabled in configuration.');

            return EXIT_ERROR;
        }

        try {
            $id = service('contabil')->createEntity()->execute(
                (string) $this->option($params, 'name'),
                $this->option($params, 'tax-id'),
                $this->option($params, 'currency'),
                $this->option($params, 'basis'),
            );
        } catch (ContabilException $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        CLI::write('Entity created with id ' . $id . '.', 'green');

        return EXIT_SUCCESS;
    }
}
