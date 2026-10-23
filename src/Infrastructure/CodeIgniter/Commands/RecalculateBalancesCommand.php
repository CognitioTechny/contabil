<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Infrastructure\CodeIgniter\Commands;

use CodeIgniter\CLI\CLI;
use CognitioTechny\Contabil\Domain\Exception\ContabilException;

class RecalculateBalancesCommand extends ContabilCommand
{
    protected $name        = 'contabil:recalculate-balances';
    protected $description = 'Rebuilds the balance cache from the entry lines.';
    protected $usage       = 'contabil:recalculate-balances [--entity <id>]';
    protected $options     = [
        '--entity' => 'Entity to rebuild; every entity when omitted.',
    ];

    public function run(array $params)
    {
        if (! $this->commandsEnabled()) {
            CLI::error('The Contabil commands are disabled in configuration.');

            return EXIT_ERROR;
        }

        try {
            foreach ($this->entityIds($this->option($params, 'entity')) as $entityId) {
                $rows = service('contabil')->recalculateBalances()->execute($entityId);

                CLI::write(sprintf('Entity %d: %d balance rows written.', $entityId, $rows), 'green');
            }
        } catch (ContabilException $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        return EXIT_SUCCESS;
    }
}
