<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Infrastructure\CodeIgniter\Commands;

use CodeIgniter\CLI\CLI;
use CognitioTechny\Contabil\Infrastructure\CodeIgniter\Database\SchemaVersion;

class StatusCommand extends ContabilCommand
{
    protected $name        = 'contabil:status';
    protected $description = 'Shows which Contabil migrations are applied and which are pending.';
    protected $usage       = 'contabil:status';

    public function run(array $params)
    {
        if (! $this->commandsEnabled()) {
            CLI::error('The Contabil commands are disabled in configuration.');

            return EXIT_ERROR;
        }

        $runner   = $this->runner();
        $applied  = [];

        foreach ($runner->getHistory($this->dbGroup()) as $row) {
            if ($row->namespace === self::MIGRATION_NAMESPACE) {
                $applied[$row->version] = $row->batch;
            }
        }

        $rows    = [];
        $pending = 0;

        foreach ($runner->findMigrations() as $migration) {
            $isApplied = isset($applied[$migration->version]);
            $pending  += $isApplied ? 0 : 1;
            $rows[]    = [$migration->name, $isApplied ? 'applied (batch ' . $applied[$migration->version] . ')' : 'pending'];
        }

        CLI::write('Schema version shipped: ' . SchemaVersion::CURRENT);
        CLI::table($rows, ['Migration', 'Status']);
        CLI::write($pending === 0 ? 'Nothing pending.' : $pending . ' migration(s) pending; run contabil:migrate.', $pending === 0 ? 'green' : 'yellow');

        return EXIT_SUCCESS;
    }
}
