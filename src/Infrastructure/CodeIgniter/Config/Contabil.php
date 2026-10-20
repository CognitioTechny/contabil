<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Infrastructure\CodeIgniter\Config;

use CodeIgniter\Config\BaseConfig;
use CognitioTechny\Contabil\Application\Configuration\Settings;

/**
 * Package defaults. Hosts override them in `app/Config/Contabil.php` (published
 * by `contabil:install`) or through `contabil.*` environment variables.
 */
class Contabil extends BaseConfig
{
    /**
     * Prefix of every package table. Changing it after the first migration
     * requires renaming the tables by hand.
     */
    public string $tablePrefix = 'acc_';

    /**
     * ISO 4217 code used when an entity does not define its own.
     */
    public string $currency = 'USD';

    /**
     * Entity used by the default CurrentEntityInterface implementation.
     */
    public int|string|null $defaultEntityId = null;

    /**
     * Base route of the API, without leading or trailing slash.
     */
    public string $apiRoute = 'contabil';

    /**
     * Registers the API routes. Switch off when the host exposes its own.
     */
    public bool $routesEnabled = true;

    /**
     * Registers the `contabil:*` spark commands.
     */
    public bool $commandsEnabled = true;

    /**
     * Basis new entities start with: ACCRUAL or CASH.
     */
    public string $defaultBasis = Settings::BASIS_ACCRUAL;

    /**
     * Database group the package tables live in; null uses the default group.
     */
    public ?string $dbGroup = null;

    public function settings(): Settings
    {
        return new Settings(
            $this->tablePrefix,
            $this->currency,
            $this->defaultEntityId,
            $this->apiRoute,
            $this->routesEnabled,
            $this->commandsEnabled,
            $this->defaultBasis,
            $this->dbGroup,
        );
    }
}
