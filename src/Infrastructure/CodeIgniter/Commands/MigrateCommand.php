<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Infrastructure\CodeIgniter\Commands;

use CodeIgniter\CLI\CLI;
use Throwable;

class MigrateCommand extends ContabilCommand
{
    protected $name        = 'contabil:migrate';
    protected $description = 'Runs the pending Contabil database migrations.';
    protected $usage       = 'contabil:migrate';

    public function run(array $params)
    {
        if (! $this->commandsEnabled()) {
            return $this->fail('The Contabil commands are disabled in configuration.');
        }

        try {
            $this->runner()->latest();
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        CLI::write('Contabil migrations are up to date.', 'green');

        return EXIT_SUCCESS;
    }

    private function fail(string $message): int
    {
        CLI::error($message);

        return EXIT_ERROR;
    }
}
