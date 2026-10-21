# Configuration

`php spark contabil:install` copies `config/contabil.php` to `app/Config/Contabil.php`. That file extends the package defaults, so you only uncomment what you change. Every property can also be set from `.env` (`contabil.tablePrefix = 'ledger_'`).

| Property | Default | Meaning |
|---|---|---|
| `tablePrefix` | `acc_` | Prefix of every package table, index and foreign key. Letters, digits and `_`, at most 16, starting with a letter. Fixed after the first migration. |
| `currency` | `USD` | Default ISO 4217 code for new entities. |
| `defaultEntityId` | `null` | Entity used by the default `CurrentEntityInterface`. |
| `apiRoute` | `contabil` | Base route of the API. |
| `routesEnabled` | `true` | Switch the API routes off when the host exposes its own. |
| `commandsEnabled` | `true` | Switch the `contabil:*` commands off. |
| `defaultBasis` | `ACCRUAL` | Basis new entities start with (`ACCRUAL` or `CASH`). |
| `dbGroup` | `null` | Database group for the package tables; `null` is the default group. |
| `accountCodeMask` | `null` | Format of account codes per level, for example `9.9.99.999` (`9` digit, `A` letter, `X` letter or digit). `null` accepts any code. See [chart-of-accounts.md](chart-of-accounts.md#code-mask). |

Invalid values raise `ConfigurationException` when the settings are built.

## Registering the package

Composer's PSR-4 map already contains `CognitioTechny\Contabil\Infrastructure\CodeIgniter`, and CodeIgniter discovers Composer namespaces when `Modules::$discoverInComposer` is `true` (the default). Nothing else is needed.

If discovery is off, add one line to `app/Config/Autoload.php`:

```php
public $psr4 = [
    // ...
    'CognitioTechny\Contabil\Infrastructure\CodeIgniter' => ROOTPATH . 'vendor/cognitiotechny/contabil/src/Infrastructure/CodeIgniter',
];
```

## Commands

| Command | Effect |
|---|---|
| `contabil:install [--force]` | Publishes the config (skipped if present, unless `--force`) and runs the migrations. |
| `contabil:migrate` | Runs pending package migrations only. |
| `contabil:status` | Lists each package migration as applied or pending. |
| `contabil:create-entity --name <n> [--tax-id] [--currency] [--basis]` | Registers an accounted entity and prints its id. |
| `contabil:seed-chart --model <generic\|professional-services> [--entity <id>]` | Seeds an entity with an optional chart; safe to repeat. |
