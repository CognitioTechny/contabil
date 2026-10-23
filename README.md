# contabil

Agnostic, headless, double-entry accounting package for CodeIgniter 4 applications (PHP 8.2+, CodeIgniter 4.7+). MIT licensed.

It gives a host application a chart of accounts, a fiscal calendar, real double-entry journal entries (immutable once posted, corrected by reversal), period and year closing, and the books and statements: General Ledger, Journal, Trial Balance, Income Statement (cash or accrual basis) and Balance Sheet. Money is an integer in minor units. It has no views and no dependency on the host: the host plugs in through ports and events.

```bash
composer require cognitiotechny/contabil
php spark contabil:install      # publishes the config and runs the migrations
# register the host adapters (ports) in app/Config/Services.php
```

Then create an entity, a chart and a fiscal year, and post:

```php
$uc = service('contabil');

$entity = $uc->createEntity()->execute('Acme Ltd');
$uc->createFiscalYear()->execute($entity, '2026-01-01');
// ... create accounts (or contabil:seed-chart), then:

$uc->createEntry()->execute($entity, new EntryData('2026-03-05', 'Invoice 1042', [
    ['account_id' => $receivables, 'side' => 'D', 'amount' => 250000],
    ['account_id' => $sales,       'side' => 'C', 'amount' => 250000],
], null, 'invoice', '1042', 'erp:invoice:1042:1'), post: true);
```

or over HTTP: `POST /contabil/entries` with the same data ([openapi.yaml](docs/openapi.yaml)).

## Documentation

- [Installation](docs/installation.md)
- [Configuration and commands](docs/configuration.md)
- [Ports](docs/ports.md)
- [Chart of accounts and calendar](docs/chart-of-accounts.md)
- [Entries, periods and closing](docs/entries.md)
- [Books and statements](docs/reports.md)
- [Events](docs/events.md)
- [Posting rules](docs/posting-rules.md)
- [Integration guide](docs/integration-guide.md)
- [Migrating legacy data](docs/migration-guide.md)
- [HTTP API (OpenAPI)](docs/openapi.yaml)
- [Database schema](docs/schema.md)
- [Errors](docs/errors.md)
- [FAQ](docs/faq.md)
- [Versioning and breaking changes](docs/versioning.md)
- [Implementation plan](docs/implementation-plan.md)

The package is a bookkeeping tool, not an accounting opinion. Charts of accounts and statement layouts need review by an accountant before official use. Tax, payroll, bank reconciliation, fixed assets, multi-currency and consolidation are out of scope.

## Development

```bash
composer install
composer test        # PHPUnit bundled with CodeIgniter 4, SQLite in memory
composer cs          # CodeIgniter coding standard (composer cs-fix to apply)
```

To run the database tests on MySQL, set the `database.tests.*` variables shown in `phpunit.xml.dist`. See [CONTRIBUTING.md](CONTRIBUTING.md).
