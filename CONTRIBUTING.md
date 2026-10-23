# Contributing

Thank you for helping. A few rules keep the package trustworthy as an accounting engine.

## Ground rules

- **Domain and Application stay framework-free and host-free.** They may use only their own namespaces and PSR interfaces. `tests/Architecture/DependencyRuleTest.php` fails the build otherwise.
- **No host references anywhere**: no host table, class, foreign key or `use`. Links to source documents stay opaque (`source_type`, `source_id`, `idempotency_key`).
- **Money is an integer in minor units.** Never `float`.
- **A posted entry is never changed.** Corrections are reversals.
- **English** for code, schema, comments and documentation.
- **Additive schema within a major version** ([docs/versioning.md](docs/versioning.md)). Never edit a released migration; add a new one, raise `SchemaVersion::CURRENT`, and test `down()`.
- **Public behaviour changes need the docs.** A route needs an entry in `docs/openapi.yaml` (`OpenApiContractTest` checks both directions), a rule needs a sentence in the relevant guide, and everything goes in the CHANGELOG.

## Setup

```bash
composer install
composer test
composer cs
```

Tests use the PHPUnit and test support shipped with CodeIgniter 4 (`CIUnitTestCase`, `DatabaseTestTrait`, `FeatureTestTrait`); do not add another test framework. Logic in Domain and Application is tested without a database, using the in-memory doubles in `tests/_support`. Anything touching SQL is tested against a real database (SQLite in memory by default; MySQL through the `database.tests.*` variables in `phpunit.xml.dist`).

Reports are checked against a hand-computed dataset: if you change a rule, recompute the expected numbers by hand and say how in the test.

## Style

`composer cs` runs the CodeIgniter coding standard through PHP CS Fixer; `composer cs-fix` applies it. CI fails on any difference.

## Pull requests

1. One logical change per pull request, with tests.
2. `composer test` and `composer cs` pass.
3. Update `CHANGELOG.md` under **Unreleased**.
4. If it touches installation or integration, run the independence check from CI locally (the steps are in `.github/workflows/ci.yml`, job `independence`).

## Reporting issues

Include the package, PHP, CodeIgniter and database versions, the smallest sequence of calls that reproduces it, and what you expected. For anything that could let a posted entry change, or a number come out wrong, say so in the title.
