# Installation

Requirements: PHP 8.2 or newer, CodeIgniter 4.7 or newer, Composer, and a database CodeIgniter supports (MySQL or SQLite are tested).

## Three commands

```bash
composer require cognitiotechny/contabil
php spark contabil:install      # publishes the config and runs the migrations
# register the host adapters (ports) in app/Config/Services.php
```

`contabil:install` copies `config/contabil.php` to `app/Config/Contabil.php` (skipped when it exists, unless `--force`) and runs only the package migrations, in their own namespace, so your own migrations are untouched. Run `php spark contabil:status` any time to see what is applied.

## First data

```bash
php spark contabil:create-entity --name "Acme Ltd" --currency EUR
php spark contabil:seed-chart --model generic --entity 1      # optional chart, review it with your accountant
```

Then, from code or the API, create a fiscal year (`POST /contabil/fiscal-years`, or `service('contabil')->createFiscalYear()->execute(1, '2026-01-01')`) and post.

## Wiring the host

The package is closed by default: the HTTP API answers 403 until you register an authorizer. Define the port services in `app/Config/Services.php`; the host definition wins over the package:

```php
use CognitioTechny\Contabil\Application\Ports\AuthorizerInterface;
use CognitioTechny\Contabil\Application\Ports\CurrentEntityInterface;
use CognitioTechny\Contabil\Application\Ports\CurrentUserInterface;

public static function contabilAuthorizer(bool $getShared = true): AuthorizerInterface
{
    return $getShared ? static::getSharedInstance('contabilAuthorizer') : new \App\Accounting\HostAuthorizer(service('auth'));
}

public static function contabilCurrentUser(bool $getShared = true): CurrentUserInterface
{
    return $getShared ? static::getSharedInstance('contabilCurrentUser') : new \App\Accounting\HostCurrentUser(service('auth'));
}

public static function contabilCurrentEntity(bool $getShared = true): CurrentEntityInterface
{
    return $getShared ? static::getSharedInstance('contabilCurrentEntity') : new \App\Accounting\HostCurrentEntity(service('session'));
}
```

The services are `contabilAuthorizer`, `contabilCurrentUser`, `contabilCurrentEntity`, `contabilClock`, `contabilEventDispatcher` and `contabilPdfRenderer`. Run the contract tests against each adapter ([ports.md](ports.md#running-the-contract-tests-against-your-adapter)). The full list of permissions is in [entries.md](entries.md#permissions) and [reports.md](reports.md).

## Choosing how to expose it

- **HTTP API**: on by default under `/contabil`. Put your own auth filter on the group, or turn the routes off with `$routesEnabled = false` and expose your own controllers.
- **Code**: `service('contabil')` returns the use cases (`createEntry()`, `reverseEntry()`, `trialBalance()`, ...). Controllers and commands use exactly these.
- **Per-module activation**: `routesEnabled` and `commandsEnabled` switch each surface off, and `AuthorizerInterface` can deny any `contabil.*` permission.

## Operations

| Command | Use |
|---|---|
| `contabil:verify-integrity [--entity]` | Check the hash chain and the numbering; schedule it nightly. Exits non-zero on a problem. |
| `contabil:recalculate-balances [--entity]` | Rebuild the balance cache from the lines. |

## Uninstalling

The package never touches host tables. To remove it, roll the package migrations back (each `down()` is tested; in code, `service('migrations')->setNamespace('CognitioTechny\Contabil\Infrastructure\CodeIgniter')->regress(0)`) or drop the `acc_*` tables, then `composer remove cognitiotechny/contabil`. Take a backup first: posted entries are the accounting record.

## Troubleshooting

- *Every API call answers 403*: no authorizer is registered. See above.
- *No routes*: check `routesEnabled`, and that Composer discovery is on (`Modules::$discoverInComposer`) or add the namespace manually ([configuration.md](configuration.md#registering-the-package)).
- *"No fiscal period exists for ..."*: create the fiscal year that covers the date.
- *SQLite "database is locked"*: the package waits up to 30 seconds for another writer; long-running writers in the same file will still exceed that. Use MySQL or PostgreSQL for concurrent workloads.
