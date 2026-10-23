<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Infrastructure\CodeIgniter\Commands;

use CodeIgniter\CLI\CLI;
use CognitioTechny\Contabil\Domain\Exception\ContabilException;

class VerifyIntegrityCommand extends ContabilCommand
{
    protected $name        = 'contabil:verify-integrity';
    protected $description = 'Walks the hash chain of posted entries and reports any edit, gap or broken link.';
    protected $usage       = 'contabil:verify-integrity [--entity <id>]';
    protected $options     = [
        '--entity' => 'Entity to check; every entity when omitted.',
    ];

    public function run(array $params)
    {
        if (! $this->commandsEnabled()) {
            CLI::error('The Contabil commands are disabled in configuration.');

            return EXIT_ERROR;
        }

        $intact = true;

        try {
            foreach ($this->entityIds($this->option($params, 'entity')) as $entityId) {
                $report = service('contabil')->integrity()->verify($entityId);

                CLI::write(sprintf('Entity %d: %d entries checked.', $entityId, $report->checked), $report->isIntact() ? 'green' : 'red');

                foreach ($report->problems as [$entryId, $message]) {
                    CLI::error(sprintf('  entry %d: %s', $entryId, $message));
                }

                $intact = $intact && $report->isIntact();
            }
        } catch (ContabilException $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        return $intact ? EXIT_SUCCESS : EXIT_ERROR;
    }
}
