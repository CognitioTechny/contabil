<?php

declare(strict_types=1);

namespace Config;

use CognitioTechny\Contabil\Infrastructure\CodeIgniter\Config\Contabil as BaseContabil;

/**
 * Host configuration for the Contabil accounting package.
 *
 * Published to `app/Config/Contabil.php` by `php spark contabil:install`.
 * Uncomment and change only what differs from the defaults; every value can
 * also be set from `.env`, for example `contabil.tablePrefix = 'ledger_'`.
 */
class Contabil extends BaseContabil
{
    // public string $tablePrefix = 'acc_';
    // public string $currency = 'USD';
    // public int|string|null $defaultEntityId = null;
    // public string $apiRoute = 'contabil';
    // public bool $routesEnabled = true;
    // public bool $commandsEnabled = true;
    // public string $defaultBasis = 'ACCRUAL'; // or 'CASH'
    // public ?string $dbGroup = null;
    // public ?string $accountCodeMask = null; // for example '9.9.99.999'
}
