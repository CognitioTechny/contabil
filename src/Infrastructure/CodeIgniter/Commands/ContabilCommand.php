<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Infrastructure\CodeIgniter\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\Database\MigrationRunner;
use CognitioTechny\Contabil\Infrastructure\CodeIgniter\Config\Contabil;
use Config\Database;
use Config\Migrations;

/**
 * Shared plumbing of the `contabil:*` commands.
 */
abstract class ContabilCommand extends BaseCommand
{
    public const MIGRATION_NAMESPACE = 'CognitioTechny\Contabil\Infrastructure\CodeIgniter';

    protected $group = 'Contabil';

    protected function runner(): MigrationRunner
    {
        $runner = new MigrationRunner(config(Migrations::class), $this->dbGroup());
        $runner->setNamespace(self::MIGRATION_NAMESPACE);

        return $runner;
    }

    /**
     * Database group the package tables live in.
     */
    protected function dbGroup(): string
    {
        return config(Contabil::class)->dbGroup ?? config(Database::class)->defaultGroup;
    }

    protected function commandsEnabled(): bool
    {
        return config(Contabil::class)->commandsEnabled;
    }
}
