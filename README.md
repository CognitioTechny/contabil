# contabil

Agnostic, headless, double-entry accounting package for CodeIgniter 4 applications (PHP 8.2+, CodeIgniter 4.7+). MIT licensed.

> Status: under development. Phase 1 (package core) is in place; see [`docs/implementation-plan.md`](docs/implementation-plan.md) for the roadmap.

```bash
composer require cognitiotechny/contabil
php spark contabil:install      # publishes config and runs migrations
# register the host adapter (ports) in configuration
```

## Documentation

- [Implementation plan](docs/implementation-plan.md)
- [Ports](docs/ports.md)
- [Configuration and commands](docs/configuration.md)
- [Database schema](docs/schema.md)
- [Errors](docs/errors.md)

## Development

```bash
composer install
vendor/bin/phpunit
```

The tests use the PHPUnit bundled with CodeIgniter 4 and run on SQLite in memory. To run the database tests on MySQL, set the `database.tests.*` variables shown in `phpunit.xml.dist`.
