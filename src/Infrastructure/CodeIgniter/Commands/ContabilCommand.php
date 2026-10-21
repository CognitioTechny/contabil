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

    /**
     * Value of a `--name value` or `--name=value` option, null when absent.
     * Spark passes the options in `$params`, keyed by option name.
     *
     * @param array<int|string, mixed> $params
     */
    protected function option(array $params, string $name): ?string
    {
        if (is_string($params[$name] ?? null)) {
            return $params[$name];
        }

        foreach (array_keys($params) as $key) {
            if (is_string($key) && str_starts_with($key, $name . '=')) {
                return substr($key, strlen($name) + 1);
            }
        }

        return null;
    }

    protected function commandsEnabled(): bool
    {
        return config(Contabil::class)->commandsEnabled;
    }
}
